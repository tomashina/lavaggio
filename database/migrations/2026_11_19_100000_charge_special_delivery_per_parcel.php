<?php

use App\Models\Settings\Local\ShippingMethod;
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
            $method = ShippingMethod::where('code', 'gls_special')->lockForUpdate()->firstOrFail();
            $method->max_weight_kg = null;
            $method->settings = array_replace($method->settings ?? [], ['parcel_weight_limit_kg' => 10]);
            if ($method->name === 'GLS – posebno dostavno područje (do 10 kg)') {
                $method->name = 'GLS – posebno dostavno područje';
            }
            $method->save();

            $pages = DB::table('content_info_page_translations')->where('locale', 'hr')->where('slug', 'dostava');
            $page = $pages->lockForUpdate()->first();
            if (! $page) {
                return;
            }
            $html = str_replace([
                'Za dostavu više od 10 kg na posebno područje zatražite ponudu na <a href="mailto:webshop@lavaggio.hr">webshop@lavaggio.hr</a>.',
                '<p>Dodatne troškove dostave obračunat ćemo prema važećem cjeniku dostavne službe.</p>',
            ], [
                'Narudžbu po potrebi dijelimo u više paketa. Svaki paket do 10 kg naplaćuje se 24,99 €. Za pojedinačni artikl teži od 10 kg obratite nam se na <a href="mailto:webshop@lavaggio.hr">webshop@lavaggio.hr</a>.',
                '',
            ], $page->body_html);
            if ($html !== $page->body_html) {
                $pages->where('id', $page->id)->update(['body_html' => $html, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Preserve later edits and historical order calculations.
    }
};
