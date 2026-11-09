<?php

namespace App\Services\Integrations\Fira;

use App\Models\Sales\Order\Order;
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
        if (! in_array($paymentType, ['GOTOVINA', 'TRANSAKCIJSKI', 'KARTICA'], true)) {
            $errors[] = 'Odaberite način plaćanja za račun.';
        }
        $cancelled = $order->status()->where(function (Builder $query): void {
            OrderStatusClassification::applyCancelledQuery($query);
        })->exists();
        if ($cancelled || $order->status?->code === 'refunded') {
            $errors[] = 'Otkazana ili vraćena narudžba ne može se poslati.';
        }
        if ($order->commerceCustomerType() !== 'B2C' || data_get($order->payload, 'placed_from') !== 'frontend_checkout') {
            $errors[] = 'Ova priprema podržava samo potrošačke narudžbe iz naplate trgovine; ostale račune obradite u FIRA-i.';
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
            if ($quantity <= 0 || $amount < 0 || $rate < 0 || $rate > 1 || trim((string) $item->name) === ''
                || abs(round($amount * $rate, 2) - (float) $item->tax_amount) > 0.001) {
                $errors[] = 'Stavke ili porez narudžbe zahtijevaju ručnu provjeru u FIRA-i.';

                continue;
            }
            $lines[] = [
                'name' => (string) $item->name,
                'lineItemId' => (string) ($item->code ?: $item->sku ?: 'ITEM-'.$item->id),
                'price' => round($amount / $quantity, 8),
                'quantity' => $quantity,
                'unit' => 'kom',
                'taxRate' => $rate,
            ];
            $net += $amount;
            $tax += (float) $item->tax_amount;
        }
        $shipping = (float) $order->shipping_total;
        $shippingRate = (float) data_get($order->payload, 'shipping.tax_rate_percent', 0) / 100;
        $shippingTax = (float) data_get($order->payload, 'shipping.tax_total', 0);
        if ($shipping < 0 || $shippingRate < 0 || $shippingRate > 1 || abs(round($shipping * $shippingRate, 2) - $shippingTax) > 0.001) {
            $errors[] = 'Porez dostave zahtijeva ručnu provjeru u FIRA-i.';
        }
        if ($shipping > 0) {
            $lines[] = ['name' => 'Dostava – '.($order->shipping_method_name ?: 'Dostava'), 'lineItemId' => 'SHIPPING',
                'price' => $shipping, 'quantity' => 1, 'unit' => 'usluga', 'taxRate' => $shippingRate];
            $net += $shipping;
            $tax += $shippingTax;
        }
        if ((float) $order->payment_fee_total !== 0.0) {
            $errors[] = 'Narudžbu s naknadom plaćanja obradite u FIRA-i dok se ne potvrdi porez naknade.';
        }
        if (abs(round($tax, 2) - (float) $order->tax_total) > 0.001
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
            'invoiceType' => $this->settings->options()['document_type'],
            'paymentType' => $paymentType,
            'paymentGatewayName' => (string) $order->payment_method_name,
            'b2bClient' => false,
            'currency' => 'EUR',
            'taxesIncluded' => false,
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
            'company' => (string) $order->{$prefix.'_company'},
            'oib' => (string) $order->{$prefix.'_oib'},
        ];
    }
}
