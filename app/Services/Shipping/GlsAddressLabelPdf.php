<?php

namespace App\Services\Shipping;

use App\Models\Sales\Order\Order;
use App\Services\Settings\SystemSettingsService;
use App\Support\GlsShipping;
use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;

class GlsAddressLabelPdf
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function data(Order $order): array
    {
        if (! GlsShipping::isGlsShippingMethod($order->shipping_method_code)) {
            throw new InvalidArgumentException('Adresna naljepnica dostupna je za GLS dostave.');
        }

        $sender = [];
        foreach (['name', 'contact_name', 'contact_phone', 'street', 'address_line_2', 'postal_code', 'city', 'country_code'] as $field) {
            $sender[$field] = trim((string) $this->settings->get('gls_api_pickup_'.$field, ''));
        }
        // Use one complete address source; never mix the store and pickup streets/cities.
        if ($sender['street'] === '' && $sender['postal_code'] === '' && $sender['city'] === '') {
            $sender = [
                'name' => trim((string) $this->settings->get('store_schema_business_name', ''))
                    ?: trim((string) $this->settings->get('store_brand_name', '')),
                'contact_name' => '',
                'contact_phone' => trim((string) $this->settings->get('store_schema_business_phone', '')),
                'street' => trim((string) $this->settings->get('store_schema_address_street', '')),
                'address_line_2' => '',
                'postal_code' => trim((string) $this->settings->get('store_schema_address_postal_code', '')),
                'city' => trim((string) $this->settings->get('store_schema_address_city', '')),
                'country_code' => trim((string) $this->settings->get('store_schema_address_country', '')),
            ];
        }
        $this->requireFields($sender, ['name', 'street', 'postal_code', 'city', 'country_code'],
            'Unesite naziv i potpunu adresu pošiljatelja u Dostava > GLS > Adresa preuzimanja.');

        $recipient = [
            'name' => trim($order->shipping_first_name.' '.$order->shipping_last_name) ?: trim((string) $order->customer_name),
            'company' => trim((string) $order->shipping_company),
            'phone' => trim((string) $order->customer_phone),
            'email' => trim((string) $order->customer_email),
        ];
        $this->requireFields($recipient, ['name'], 'Narudžba nema ime primatelja.');
        $isLocker = GlsShipping::isGlsDpmShippingMethod($order->shipping_method_code);
        $point = (array) data_get($order->payload, 'shipping.gls_dpm', []);
        $destination = $isLocker ? [
            'id' => trim((string) ($point['id'] ?? '')),
            'name' => trim((string) ($point['name'] ?? '')),
            'street' => trim((string) ($point['address_line_1'] ?? '')),
            'address_line_2' => '',
            'postal_code' => trim((string) ($point['postal_code'] ?? '')),
            'city' => trim((string) ($point['city'] ?? '')),
            'country_code' => trim((string) ($point['country_code'] ?? $order->shipping_country_code)),
        ] : [
            'street' => trim((string) $order->shipping_address_line_1),
            'address_line_2' => trim((string) $order->shipping_address_line_2),
            'postal_code' => trim((string) $order->shipping_postal_code),
            'city' => trim((string) $order->shipping_city),
            'country_code' => trim((string) $order->shipping_country_code),
        ];
        $this->requireFields($destination, $isLocker
            ? ['id', 'name', 'street', 'postal_code', 'city', 'country_code']
            : ['street', 'postal_code', 'city', 'country_code'], $isLocker
                ? 'Narudžba nema potpunu adresu i ID GLS paketomata. Provjerite odabranu GLS lokaciju prije ispisa.'
                : 'Narudžba nema potpunu adresu dostave. Provjerite ulicu, poštanski broj, grad i državu.');

        return compact('sender', 'recipient', 'destination', 'isLocker') + [
            'orderNumber' => $order->order_number,
            'serviceName' => $order->shipping_method_name ?: ($isLocker ? 'GLS paketomat' : 'GLS dostava na adresu'),
        ];
    }

    public function render(Order $order): string
    {
        $data = $this->data($order);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [resource_path('views/admin/orders')]);

        foreach ([10, 9, 8] as $fontSize) {
            $pdf = new Dompdf($options);
            $pdf->setPaper([0, 0, 100 * 72 / 25.4, 150 * 72 / 25.4]);
            $pdf->loadHtml(view('admin.orders.address-label', $data + compact('fontSize'))->render(), 'UTF-8');
            $pdf->render();
            if ($pdf->getCanvas()->get_page_count() === 1) {
                return $pdf->output();
            }
        }

        throw new InvalidArgumentException('Podaci su predugi za naljepnicu 10 × 15 cm. Provjerite duljinu naziva i adresa prije ispisa.');
    }

    private function requireFields(array $values, array $fields, string $message): void
    {
        foreach ($fields as $field) {
            if (($values[$field] ?? '') === '') {
                throw new InvalidArgumentException($message);
            }
        }
    }
}
