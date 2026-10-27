<?php

namespace Database\Seeders;

use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;

class LavaggioStorefrontSeeder extends Seeder
{
    public function run(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Lavaggio',
            'store_brand_logo_path' => 'assets/lavaggio/images/logo/lavaggio-logo-dark.svg',
            'store_brand_logo_dark_path' => 'assets/lavaggio/images/logo/lavaggio-logo-light.svg',
            'store_brand_logo_optimized_path' => '',
            'store_brand_logo_width' => 220,
            'store_brand_logo_height' => 50,
            'store_brand_favicon_path' => 'assets/lavaggio/favicon.svg',
            'store_seo_default_title' => 'Lavaggio webshop · Sve za njegu rublja',
            'store_seo_default_description' => 'Lavaggio webshop – proizvodi za pranje, svježinu i njegu rublja.',
            'store_seo_robots' => 'noindex,nofollow',
            'store_schema_business_name' => 'Lavaggio · Bareda d.o.o.',
            'store_schema_business_email' => 'info@lavaggio.hr',
            'store_schema_business_phone' => '097 799 44 11',
            'store_schema_blog_author_name' => 'Lavaggio',
            'store_footer_contact_title' => 'Lavaggio',
            'store_footer_contact_intro' => 'Sve za čisto, svježe i njegovano rublje.',
            'store_footer_email_sales' => 'info@lavaggio.hr',
            'store_footer_email_support' => 'info@lavaggio.hr',
            'store_footer_phone' => '097 799 44 11',
            'store_footer_address' => "Bareda d.o.o.\nBarban 70\n52207 Barban\nOIB: 96889385802",
            'store_footer_hours' => '',
            'store_footer_bottom_copyright_text' => 'Lavaggio® · Sva prava pridržana.',
            'store_footer_col_1_title' => 'Web shop',
            'store_footer_col_1_title_translations' => ['hr' => 'Web shop'],
            'store_footer_col_1_category_ids' => [],
            'store_footer_col_2_title' => 'Informacije',
            'store_footer_col_2_title_translations' => ['hr' => 'Informacije'],
            'store_footer_col_2_page_ids' => [],
            'store_footer_col_2_custom_links' => '',
            'store_footer_col_3_title' => 'Korisne poveznice',
            'store_footer_col_3_title_translations' => ['hr' => 'Korisne poveznice'],
            'store_footer_col_3_custom_links' => 'Moj račun|'.route('front.auth.login', absolute: false)."\nKošarica|/cart\nLavaggio praonica|https://www.lavaggio.hr/",
            'store_footer_bottom_link_page_ids' => [],
            'store_social_facebook_url' => 'https://www.facebook.com/lavaggio.praonica',
            'store_social_instagram_url' => 'https://www.instagram.com/lavaggio.hr/',
            'store_social_tiktok_url' => 'https://www.tiktok.com/@lavaggio.hr',
            'store_social_youtube_url' => 'https://www.youtube.com/@lavaggiohr',
            'store_footer_social_facebook_enabled' => true,
            'store_footer_social_instagram_enabled' => true,
            'store_footer_social_tiktok_enabled' => true,
            'store_footer_social_youtube_enabled' => true,
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Besplatna dostava za narudžbe iznad 50 €',
            'store_announcement_background_color' => '#848ac4',
            'store_announcement_text_color' => '#13100d',
            'store_benefits_bar_enabled' => false,
            'store_benefits_bar_item_1' => '',
            'store_benefits_bar_item_2' => '',
            'store_benefits_bar_item_3' => '',
            'store_product_desktop_default_cols' => 5,
            'store_product_filter_option_ids' => [],
            'store_product_filter_attribute_group_codes' => [],
            'store_pricing_b2c_enabled' => true,
            'store_search_autocomplete_enabled' => true,
            'store_newsletter_enabled' => true,
            'store_newsletter_title' => 'Svježina ravno u vaš inbox.',
            'store_newsletter_subtitle' => 'Prijavite se za novosti iz Lavaggio svijeta i buduće ponude webshopa.',
            'store_newsletter_button_label' => 'Prijavi se',
            'store_newsletter_consent_label' => 'Pristajem na primanje Lavaggio newslettera i obradu podataka za tu svrhu.',
            'store_newsletter_provider' => 'database',
            'store_newsletter_coupon_enabled' => false,
            'store_email_enabled' => false,
            'store_analytics_enabled' => false,
            'store_legal_warranty_enabled' => false,
            'msan_enabled' => false,
            'msan_price_stock_sync_enabled' => false,
            'catalog_use_blog' => false,
            'catalog_use_attributes' => true,
            'catalog_use_options' => true,
            'catalog_use_manufacturers' => true,
            'catalog_hide_out_of_stock_products' => false,
            'lavaggio_free_shipping_threshold' => 50,
            'lavaggio_home_eyebrow' => 'LAVAGGIO WEBSHOP',
            'lavaggio_home_title' => 'Njega rublja s potpisom Lavaggio.',
            'lavaggio_home_description' => 'Iskustvo profesionalne praonice u vašem domu. Pažljivo odabrana njega za rublje koje volite.',
            'lavaggio_home_side_eyebrow' => 'ZA VAŠ DOM',
            'lavaggio_home_side_title' => 'Svježina u svakom pranju.',
            'lavaggio_home_side_description' => 'Mali rituali za meko, čisto i mirisno rublje.',
            'lavaggio_home_pro_eyebrow' => 'LAVAGGIO ISKUSTVO',
            'lavaggio_home_pro_title' => 'Znanje iz praonice. Njega kod kuće.',
            'lavaggio_home_pro_description' => 'Tu smo za vaša pitanja o pranju i njezi rublja.',
            'lavaggio_home_empty_description' => 'Pripremamo proizvode za čisto, svježe i njegovano rublje. Uskoro više.',
            NavigationMenuService::SETTINGS_KEY => self::navigationItems(),
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => [
                'container_width' => 1860, 'header_content_width' => 1860,
                'background_color' => '#848ac4', 'text_color' => '#13100d', 'highlight_color' => '#6c73b0',
            ],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => true, 'height' => 34, 'font_size' => 12,
                'background_color' => '#f1f2f9', 'text_color' => '#13100d', 'border_color' => '#e4e5f0',
                'links' => [
                    ['label' => 'info@lavaggio.hr', 'url' => 'mailto:info@lavaggio.hr', 'is_active' => true, 'sort_order' => 0],
                    ['label' => '097 799 44 11', 'url' => 'tel:+385977994411', 'is_active' => true, 'sort_order' => 1],
                ],
                'socials' => [
                    ['network' => 'facebook', 'url' => 'https://www.facebook.com/lavaggio.praonica', 'is_active' => true, 'sort_order' => 0],
                    ['network' => 'instagram', 'url' => 'https://www.instagram.com/lavaggio.hr/', 'is_active' => true, 'sort_order' => 1],
                ],
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function navigationItems(): array
    {
        return collect([
            ['Praonica', 'https://www.lavaggio.hr/'],
            ['Usluge', 'https://www.lavaggio.hr/usluge/'],
            ['Cjenik', 'https://www.lavaggio.hr/cjenik/'],
            ['Pitanja i odgovori', 'https://www.lavaggio.hr/pitanja-i-odgovori'],
            ['Blog', 'https://www.lavaggio.hr/blog/'],
            ['Kontakt', 'https://www.lavaggio.hr/kontakt'],
        ])->map(fn (array $item, int $index): array => [
            'type' => 'custom', 'label' => $item[0], 'url' => $item[1],
            'is_active' => true, 'sort_order' => 100 + $index * 10,
        ])->all();
    }
}
