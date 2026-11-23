<?php

namespace App\Services\Integrations\Fira;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Pricing\VatInclusiveLine;
use App\Support\OrderStatusClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** FIRA v1.5.1: amounts come exclusively from the immutable checkout snapshot. */
class FiraOrderPayload
{
    public function __construct(private readonly FiraSettingsService $settings) {}

    public function build(Order $order, string $paymentType): array
    {
        $order->loadMissing('items', 'status');
        $errors = [];
        $isBusiness = $order->commerceCustomerType() === 'B2B' || trim((string) $order->billing_company) !== '';
        $documentType = $this->settings->options()['document_type'];
        if (! in_array($paymentType, ['GOTOVINA', 'TRANSAKCIJSKI', 'KARTICA'], true)) {
            $errors[] = 'Odaberite način plaćanja za račun.';
        }
        $cancelled = $order->status()->where(function (Builder $query): void {
            OrderStatusClassification::applyCancelledQuery($query);
        })->exists();
        if ($cancelled || $order->status?->code === 'refunded') {
            $errors[] = 'Otkazana ili vraćena narudžba ne može se poslati.';
        }
        if (data_get($order->payload, 'placed_from') !== 'frontend_checkout') {
            $errors[] = 'Ova priprema podržava narudžbe iz naplate trgovine; ručno unesene narudžbe obradite u FIRA-i.';
        }
        if ($isBusiness) {
            if (trim((string) $order->billing_company) === '') {
                $errors[] = 'Za poslovnog kupca nedostaje naziv tvrtke u adresi naplate.';
            }
            $oib = trim((string) $order->billing_oib);
            if ($oib === '' || (strtoupper(trim((string) $order->billing_country_code)) === 'HR' && ! preg_match('/^[0-9]{11}$/', $oib))) {
                $errors[] = 'Za poslovnog kupca unesite OIB u adresi naplate; hrvatski OIB mora sadržavati 11 znamenki.';
            }
            if ($documentType === 'FISKALNI_RAČUN' && $paymentType === 'TRANSAKCIJSKI') {
                $errors[] = 'Za poslovnog kupca s bankovnim plaćanjem izradite ponudu, a zatim eRačun obradite u FIRA-i. Fiskalni račun iz webshopa podržan je za karticu i gotovinu.';
            }
        }
        if ($order->currency_code !== 'EUR' || (float) $order->grand_total <= 0 || $order->items->isEmpty()) {
            $errors[] = 'Potrebna je neprazna narudžba u eurima s pozitivnim iznosom.';
        }
        if ((float) data_get($order->payload, 'gift_voucher_total', 0) > 0) {
            $errors[] = 'Narudžbu plaćenu poklon bonom obradite u FIRA-i.';
        }
        if ((float) $order->discount_total - (float) $order->items->sum('discount_amount') > 0.001) {
            $errors[] = 'Popust na cijelu košaricu zahtijeva ručnu obradu u FIRA-i.';
        }
        foreach (['customer_name', 'customer_email', 'billing_address_line_1', 'billing_city', 'billing_postal_code', 'billing_country_code'] as $field) {
            if (trim((string) $order->$field) === '') {
                $errors[] = 'Za račun nedostaju podaci kupca i adresa naplate.';
                break;
            }
        }
        $lines = [];
        $tax = 0.0;
        $net = 0.0;
        foreach ($order->items as $item) {
            $quantity = (int) $item->quantity;
            $amount = (float) $item->line_total;
            $rate = (float) $item->tax_rate / 100;
            if ($quantity <= 0 || $amount < 0 || (float) $item->tax_amount < 0 || $rate < 0 || $rate > 1 || trim((string) $item->name) === '') {
                $errors[] = 'Stavke ili porez narudžbe zahtijevaju ručnu provjeru u FIRA-i.';

                continue;
            }
            $sku = trim((string) $item->sku) ?: trim((string) $item->code);
            $line = [
                'name' => (string) $item->name,
                'lineItemId' => $sku !== '' ? $sku : 'ITEM-'.$item->id,
                'unit' => 'kom',
                'taxRate' => $rate,
            ];
            if ($sku !== '') {
                $line['productCode'] = $sku;
            }
            array_push($lines, ...$this->grossLines($line, $quantity, $amount, (float) $item->tax_amount));
            $net += $amount;
            $tax += (float) $item->tax_amount;
        }
        $shipping = (float) $order->shipping_total;
        $shippingRate = (float) data_get($order->payload, 'shipping.tax_rate_percent', 0) / 100;
        $shippingTax = (float) data_get($order->payload, 'shipping.tax_total', 0);
        $parcelCount = (int) data_get($order->payload, 'shipping.calculation.parcel_count', 1);
        $unitNet = (float) data_get($order->payload, 'shipping.calculation.parcel_unit_net', $shipping);
        $expectedGross = round($unitNet * $parcelCount * (1 + $shippingRate), 2);
        if ($parcelCount < 1 || $shipping < 0 || $shippingRate < 0 || $shippingRate > 1
            || abs(round($unitNet * $parcelCount, 2) - $shipping) > 0.001
            || abs($expectedGross - $shipping - $shippingTax) > 0.001) {
            $errors[] = 'Porez dostave zahtijeva ručnu provjeru u FIRA-i.';
        }
        if ($shipping > 0) {
            // Older orders predate the mapping snapshot; only their identifiers fall back to admin settings.
            $shippingSettings = ShippingMethod::where('code', $order->shipping_method_code)->value('settings');
            $shippingSku = trim((string) data_get($order->payload, 'shipping.fira_product_code', data_get($shippingSettings, 'fira_product_code')));
            $shippingKpd = trim((string) data_get($order->payload, 'shipping.fira_kpd_code', data_get($shippingSettings, 'fira_kpd_code')));
            $shippingLine = ['name' => $order->shipping_method_name ?: 'Dostava', 'lineItemId' => $shippingSku ?: 'SHIPPING',
                'unit' => 'usluga', 'taxRate' => $shippingRate];
            if ($shippingSku !== '') {
                $shippingLine['productCode'] = $shippingSku;
            }
            if ($shippingKpd !== '') {
                $shippingLine['kpdCode'] = $shippingKpd;
            }
            if ($parcelCount > 0 && $shippingRate >= 0 && $shippingRate <= 1 && $shippingTax >= 0) {
                array_push($lines, ...$this->grossLines($shippingLine, $parcelCount, $shipping, $shippingTax));
            }
            $net += $shipping;
            $tax += $shippingTax;
        }
        if ((float) $order->payment_fee_total !== 0.0) {
            $errors[] = 'Narudžbu s naknadom plaćanja obradite u FIRA-i dok se ne potvrdi porez naknade.';
        }
        $sentCents = array_sum(array_map(static fn (array $line) => (int) round($line['price'] * 100) * $line['quantity'], $lines));
        if ($sentCents !== (int) round((float) $order->grand_total * 100)
            || abs(round($tax, 2) - (float) $order->tax_total) > 0.001
            || abs(round($net + $tax, 2) - (float) $order->grand_total) > 0.001) {
            $errors[] = 'Zbroj stavki i poreza ne odgovara ukupnom iznosu narudžbe.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['fira' => array_values(array_unique($errors))]);
        }

        $payload = [
            'webshopOrderId' => (int) $order->id,
            'webshopOrderNumber' => (string) $order->order_number,
            'webshopType' => 'CUSTOM',
            'webshopEvent' => 'order_created',
            'invoiceType' => $documentType,
            'paymentType' => $paymentType,
            'paymentGatewayName' => (string) $order->payment_method_name,
            'b2bClient' => $isBusiness,
            'currency' => 'EUR',
            'taxesIncluded' => true,
            'billingAddress' => $this->address($order, 'billing'),
            'shippingAddress' => $this->address($order, 'shipping'),
            'lineItems' => $lines,
            'netto' => round($net, 2),
            'taxValue' => round($tax, 2),
            'brutto' => (float) $order->grand_total,
        ];
        // The API accepts prepaidAmount on fiscal invoices paid by bank transfer only.
        if ($payload['invoiceType'] === 'FISKALNI_RAČUN' && $order->paid_at && $paymentType === 'TRANSAKCIJSKI') {
            $payload['prepaidAmount'] = (float) $order->grand_total;
        }

        return $payload;
    }

    private function grossLines(array $line, int $quantity, float $net, float $tax): array
    {
        $netCents = (int) round($net * 100);
        $grossCents = $netCents + (int) round($tax * 100);
        $portions = VatInclusiveLine::portions($grossCents, $quantity, $line['taxRate']);
        if (array_sum(array_column($portions, 'net_cents')) !== $netCents) {
            // Earlier checkouts rounded VAT per unit. Keep the saved tax by grouping
            // only as many identical units as still yield the very same rounded net.
            $legacy = [];
            foreach ($portions as $portion) {
                $unit = $portion['unit_cents'];
                $rawNet = VatInclusiveLine::unitNetMills($unit, $line['taxRate']) / 10;
                $unitNet = (int) round($rawNet);
                $batch = 1;
                while ($batch < $portion['quantity'] && (int) round($rawNet * ($batch + 1)) === $unitNet * ($batch + 1)) {
                    $batch++;
                }
                for ($remaining = $portion['quantity']; $remaining > 0; $remaining -= $count) {
                    $count = min($remaining, $batch);
                    $legacy[] = ['unit_cents' => $unit, 'quantity' => $count, 'net_cents' => $unitNet * $count];
                }
            }
            if (array_sum(array_column($legacy, 'net_cents')) !== $netCents) {
                throw ValidationException::withMessages(['fira' => 'PDV stavke '.$line['lineItemId'].' ne odgovara spremljenoj cijeni i poreznoj stopi.']);
            }
            $portions = $legacy;
        }
        $rows = [];
        foreach ($portions as $index => $portion) {
            $row = $line;
            if ($index > 0) {
                $row['lineItemId'] .= '-'.($index + 1);
            }
            $row['price'] = $portion['unit_cents'] / 100.0;
            $row['quantity'] = $portion['quantity'];
            $rows[] = $row;
        }

        return $rows;
    }

    private function address(Order $order, string $prefix): array
    {
        return [
            'name' => trim((string) $order->{$prefix.'_first_name'}.' '.(string) $order->{$prefix.'_last_name'}) ?: (string) $order->customer_name,
            'address1' => (string) $order->{$prefix.'_address_line_1'},
            'address2' => (string) $order->{$prefix.'_address_line_2'},
            'city' => (string) $order->{$prefix.'_city'},
            'country' => (string) $order->{$prefix.'_country_code'},
            'zipCode' => (string) $order->{$prefix.'_postal_code'},
            'phone' => (string) $order->customer_phone,
            'email' => (string) $order->customer_email,
            'company' => trim((string) $order->{$prefix.'_company'}),
            'oib' => trim((string) $order->{$prefix.'_oib'}),
        ];
    }
}
