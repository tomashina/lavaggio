<?php

use App\Models\Content\Page\InfoPage;
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
            $settings = app(SystemSettingsService::class);
            $address = trim((string) $settings->get('store_footer_address', ''))
                ?: "Bareda d.o.o.\nBarban 70\n52207 Barban\nOIB: 96889385802";
            // Keep editorial address details; replace only the explicitly supplied IBAN.
            $address = preg_replace('/^\h*IBAN\h*:[^\r\n]*(?:\R|$)/miu', '', $address);
            $settings->put('store_footer_address', rtrim($address)."\nIBAN: HR9423800061171000708");

            $page = InfoPage::query()->where('code', 'lavaggio-shop-webshop-nacini-placanja')->first();
            $translation = $page?->translations()->where('locale', 'hr')->first();
            if (! $translation) {
                return;
            }

            $paragraph = '<p>Kartično plaćanje provodi se putem sustava <strong>CorvusPay</strong>. Prihvaćamo kartice <strong>Visa, Mastercard, Maestro i Diners</strong> te digitalne novčanike <strong>Apple Pay i Google Pay</strong>.</p>';
            $body = str_replace('<p>Sadržaj ove stranice je u pripremi.</p>', '', (string) $translation->body_html);
            $original = '<p>Plaćanje se obavlja tijekom završetka narudžbe putem sustava za online plaćanje.</p>';
            if (! str_contains($body, $paragraph)) {
                $body = str_contains($body, $original)
                    ? str_replace($original, $paragraph, $body)
                    : trim($body."\n".$paragraph);
            }
            if ($body !== (string) $translation->body_html) {
                $translation->update(['body_html' => $body]);
            }
        });
    }

    public function down(): void
    {
        // Preserve public payment information and later editorial changes.
    }
};
