<?php

use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        DB::transaction(function (): void {
            $promoEnd = now('Europe/Zagreb')->addDays(13);
            $home = ShippingMethod::where('code', 'gls_home')->firstOrFail();
            $standard = ['lavaggio_delivery_area' => 'standard', 'price_by_total_weight' => true];
            $this->updateMethod($home, ['name' => 'GLS – dostava na adresu (do 20 kg)', 'price' => 4.72, 'max_weight_kg' => 20, 'free_over' => 70], $standard + ['free_shipping_strict' => true]);
            $this->updateMethod(ShippingMethod::where('code', 'gls_locker')->firstOrFail(), [
                'price' => 3.92, 'free_over' => 70, 'max_weight_kg' => 20, 'max_length_cm' => 74, 'max_width_cm' => 60, 'max_height_cm' => 43,
            ], $standard + ['free_shipping_strict' => false]);
            $this->updateMethod(ShippingMethod::where('code', 'boxnow')->firstOrFail(), [
                'price' => 3.12, 'free_over' => 70, 'max_weight_kg' => 20, 'max_length_cm' => 60, 'max_width_cm' => 45, 'max_height_cm' => 36,
            ], $standard + ['free_shipping_strict' => false, 'free_shipping_until' => $promoEnd->toDateString()]);

            $heavy = ShippingMethod::where('code', 'gls_heavy')->firstOrFail();
            $this->updateMethod($heavy, [
                'name' => 'GLS – dostava na adresu (više od 20 i manje od 60 kg)',
                'min_weight_kg' => 20.001, 'max_weight_kg' => 59.999, 'free_over' => null, 'pricing_type' => 'weight_tiers',
            ], $standard + ['free_shipping_disabled' => true]);
            $heavy->rates()->delete();
            foreach ([[20.001, 24.999, 8.80], [25, 29.999, 10.40], [30, 39.999, 12.80], [40, 59.999, 25.60]] as $index => [$min, $max, $net]) {
                $heavy->rates()->create(['min_weight_kg' => $min, 'max_weight_kg' => $max, 'price' => $net, 'sort_order' => $index]);
            }
            $this->updateMethod(ShippingMethod::where('code', 'pallet')->firstOrFail(), [
                'name' => 'Paletna dostava (od 60 kg)', 'min_weight_kg' => 60, 'max_weight_kg' => null, 'free_over' => null, 'price' => 79.20,
            ], $standard + ['free_shipping_disabled' => true]);

            $special = ShippingMethod::firstOrNew(['code' => 'gls_special']);
            $special->fill([
                'name' => 'GLS – posebno dostavno područje (do 10 kg)', 'carrier' => 'gls', 'service_type' => 'home_delivery',
                'pricing_type' => 'flat', 'price' => 19.99, 'max_weight_kg' => 10, 'geo_zone_id' => $home->geo_zone_id,
                'is_active' => true, 'sort_order' => $home->sort_order, 'allows_fragile' => true, 'allows_oversized' => true, 'allows_heavy' => true,
                'missing_measurements_policy' => 'allow',
                'settings' => [
                    'destination_country_codes' => ['HR'], 'lavaggio_delivery_area' => 'special', 'require_known_weight' => true,
                    'free_shipping_disabled' => true, 'tax_rate_percent' => 25,
                    'fira_product_code' => data_get($home->settings, 'fira_product_code'), 'fira_kpd_code' => data_get($home->settings, 'fira_kpd_code'),
                ],
            ])->save();

            $pickup = ShippingMethod::where('service_type', 'pickup')->orderByDesc('is_active')->first() ?? ShippingMethod::firstOrNew(['code' => 'pup']);
            if (! $pickup->exists) {
                $pickup->fill(['name' => 'Osobno preuzimanje u Barbanu', 'sort_order' => 35, 'geo_zone_id' => $home->geo_zone_id]);
            }
            $settings = $pickup->settings ?? [];
            unset($settings['opencart_xshippingpro']);
            $pickup->fill(['is_active' => true, 'service_type' => 'pickup', 'carrier' => 'pickup', 'pricing_type' => 'free', 'price' => 0,
                'free_over' => null, 'min_subtotal' => null, 'max_subtotal' => null, 'settings' => $settings])->save();

            $store = app(SystemSettingsService::class);
            foreach (['store_email_from_address', 'store_email_reply_to', 'store_email_orders_to', 'store_email_contact_to',
                'store_footer_email_sales', 'store_footer_email_support', 'store_withdrawal_admin_email'] as $key) {
                $address = trim((string) $store->get($key));
                if ($address === '' || strcasecmp($address, 'shop@lavaggio.hr') === 0) {
                    $store->put($key, 'webshop@lavaggio.hr');
                }
            }
            $store->putMany([
                'lavaggio_free_shipping_threshold' => 70,
                'store_announcement_text' => 'Besplatna dostava iznad 70 € do 20 kg, osim za posebna dostavna područja',
            ]);
            $this->updateDeliveryPage($promoEnd->format('d.m.Y.'));
        });
    }

    private function updateMethod(ShippingMethod $method, array $attributes, array $settings): void
    {
        $method->fill($attributes + ['settings' => array_replace($method->settings ?? [], $settings)])->save();
    }

    private function updateDeliveryPage(string $promoEnd): void
    {
        $page = DB::table('content_info_page_translations')->where('locale', 'hr')->where('slug', 'dostava')->lockForUpdate()->first();
        if (! $page) {
            return;
        }

        $rates = '<table><thead><tr><th>Težina pošiljke</th><th>Cijena s PDV-om</th></tr></thead><tbody>'
            .'<tr><td>Više od 20 do manje od 25 kg</td><td>11,00 €</td></tr><tr><td>Od 25 do manje od 30 kg</td><td>13,00 €</td></tr>'
            .'<tr><td>Od 30 do manje od 40 kg</td><td>16,00 €</td></tr><tr><td>Od 40 do manje od 60 kg</td><td>32,00 €</td></tr>'
            .'<tr><td>Od 60 kg</td><td>99,00 €</td></tr></tbody></table>';
        $promotion = 'BOX NOW je besplatan do '.$promoEnd.' uključivo. Redovna cijena iznosi 3,90 €.';
        $summary = 'Za pošiljke do 20 kg GLS dostava na adresu besplatna je iznad 70 €, a dostava u paketomat od 70 €. Posebna dostavna područja imaju zasebnu tarifu.';
        $contact = 'Za dostavu više od 10 kg na posebno područje zatražite ponudu na <a href="mailto:webshop@lavaggio.hr">webshop@lavaggio.hr</a>.';
        $html = (string) $page->body_html;
        if (in_array(trim(strip_tags($html)), ['', 'Sadržaj ove stranice je u pripremi.'], true)) {
            $html = '<p>'.$summary.'</p><h2>Načini dostave</h2><h3>Osobno preuzimanje</h3><p>Besplatno u Barbanu.</p>'
                .'<h3>GLS – dostava na adresu</h3><p>5,90 €</p><h3>GLS – paketomat</h3><p>4,90 €</p><h3>BOX NOW – paketomat</h3><p>'.$promotion.'</p>'
                .'<h3>Pošiljke teže od 20 kg</h3>'.$rates.'<h3>Posebna dostavna područja</h3><p>24,99 € za paket do 10 kg. '.$contact.'</p>'
                .'<p><a href="/storage/content-documents/opcenito/827deb53-6312-4f4c-accc-c1b318b06b1e--posebna-dostavna-podruja.pdf">Popis poštanskih brojeva (PDF)</a></p>';
        } else {
            // Only replace the shop's tariff table and price statements. Preserve its links and other editorial content.
            $html = preg_replace_callback('~<table\b[^>]*>.*?</table>~is', static fn ($m) => preg_match('~20\s*kg.*25\s*kg~is', strip_tags($m[0])) ? $rates : $m[0], $html);
            $html = preg_replace('~Cijena:\s*3,90\s*€~u', $promotion, $html);
            $html = preg_replace('~Za sve narudžbe u vrijednosti od 70 € i više, uz ukupnu težinu pošiljke do 20 kg, dostava je besplatna\.~u', $summary, $html);
            $html = preg_replace('~Ako je ukupna težina narudžbe veća od 10 kg, narudžbu ćemo podijeliti u više paketa\.~u', $contact, $html);
            $html = preg_replace('~<p\b[^>]*>Naručujete veću količinu.*?</p>~isu', '<p>Za redovne webshop narudžbe iznad 20 kg vrijedi navedeni cjenik prema težini.</p>', $html);
        }
        DB::table('content_info_page_translations')->where('id', $page->id)->update(['body_html' => $html, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve subsequent editorial and tariff changes.
    }
};
