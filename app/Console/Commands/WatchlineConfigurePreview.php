<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\Language;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WatchlineConfigurePreview extends Command
{
    protected $signature = 'watchline:configure-preview {--source-connection=watchline_source} {--prefix=oc_} {--navigation-only : Update category navigation and the Watchline palette without connecting to OpenCart}';

    protected $description = 'Configure local Watchline branding, public contacts and navigation';

    public function handle(SystemSettingsService $settings): int
    {
        if (! app()->environment('local') || strcasecmp((string) config('app.name'), 'Watchline') !== 0) {
            $this->error('Restricted to the local Watchline preview.');

            return self::FAILURE;
        }

        $navigationOnly = (bool) $this->option('navigation-only');
        if ($navigationOnly) {
            $public = collect([
                'config_name' => $settings->get('store_brand_name', 'Watchline'),
                'config_email' => $settings->get('store_footer_email_sales', ''),
                'config_telephone' => $settings->get('store_footer_phone', ''),
            ]);
        } else {
            $prefix = (string) $this->option('prefix');
            $source = DB::connection((string) $this->option('source-connection'));
            if (! preg_match('/^[a-zA-Z0-9_]*$/', $prefix)
                || ! in_array($source->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
                || $source === DB::connection()) {
                $this->error('An isolated local OpenCart source is required.');

                return self::FAILURE;
            }

            // Copy only public shop information; integrations and credentials remain in the snapshot.
            $publicKeys = ['config_name', 'config_meta_title', 'config_meta_description', 'config_email', 'config_telephone', 'config_address', 'config_open'];
            $public = $source->table($prefix.'setting')->where('store_id', 0)
                ->whereIn('key', $publicKeys)->orderBy('setting_id')->pluck('value', 'key');
        }
        $text = static fn (string $key, string $fallback = ''): string => trim(strip_tags(html_entity_decode((string) $public->get($key, $fallback), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $brand = $text('config_name') ?: 'Watchline';
        $email = $text('config_email');
        $phone = $text('config_telephone');
        $logo = 'assets/brand/watchline-logo.svg';
        $darkLogo = 'assets/brand/watchline-logo-on-dark.svg';

        if (! $navigationOnly) {
            Language::updateOrCreate(['code' => 'hr'], [
                'locale' => 'hr_HR', 'name' => 'Croatian', 'native_name' => 'Hrvatski',
                'direction' => 'ltr', 'is_default' => true, 'is_active' => true, 'sort_order' => 1,
            ]);
            Currency::updateOrCreate(['code' => 'EUR'], [
                'name' => 'Euro', 'symbol' => '€', 'symbol_position' => 'right', 'decimal_places' => 2,
                'exchange_rate' => 1, 'is_default' => true, 'is_active' => true, 'sort_order' => 1,
            ]);
        }

        $navigation = [];
        foreach (['satovi' => 'Satovi', 'naocale' => 'Naočale', 'nakit' => 'Nakit'] as $slug => $label) {
            $categoryId = Category::where('scope', 'catalog')->whereNull('parent_id')->where('is_active', true)
                ->whereHas('translations', fn ($query) => $query->where('locale', 'hr')->where('slug', $slug))->value('id');
            if (! $categoryId) {
                $this->error('Import the Watchline root category before configuring navigation: '.$slug);

                return self::FAILURE;
            }
            $navigation[] = ['type' => 'category', 'category_id' => (int) $categoryId, 'label' => $label,
                'is_active' => true, 'show_dropdown' => true, 'sort_order' => count($navigation)];
        }
        $navigation[] = ['type' => 'custom', 'label' => 'Brandovi', 'url' => route('manufacturers.index', absolute: false), 'is_active' => true, 'sort_order' => 3];
        $navigation[] = ['type' => 'custom', 'label' => 'Akcije', 'url' => route('shop.promotions', absolute: false), 'is_active' => true, 'sort_order' => 4];
        if (BlogPost::where('is_active', true)->exists()) {
            $navigation[] = ['type' => 'blog', 'label' => 'Blog', 'is_active' => true, 'sort_order' => 5];
        }
        $aboutId = InfoPage::where('is_active', true)->whereHas('translations', fn ($query) => $query
            ->where('locale', 'hr')->where(fn ($query) => $query->where('slug', 'o-nama')->orWhere('title', 'like', '%o nama%')))->value('id');
        if ($aboutId) {
            $navigation[] = ['type' => 'page', 'label' => 'O nama', 'page_id' => $aboutId, 'is_active' => true, 'sort_order' => 6];
        }
        $navigation[] = ['type' => 'contact', 'label' => 'Kontakt', 'is_active' => true, 'sort_order' => 7];
        $navigation[] = ['type' => 'custom', 'label' => 'Poklon bon', 'url' => route('gift-vouchers.create', absolute: false), 'icon' => 'gift', 'align_right' => true, 'is_active' => true, 'sort_order' => 8];
        $navigation = \App\Support\WatchlineGiftGuideNavigation::addTo($navigation);

        $links = [];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $links[] = ['label' => $email, 'url' => 'mailto:'.$email, 'is_active' => true, 'sort_order' => 0];
        }
        if ($phone !== '') {
            $links[] = ['label' => $phone, 'url' => 'tel:'.preg_replace('/[^+0-9]/', '', $phone), 'is_active' => true, 'sort_order' => 1];
        }
        // Verified public links from the production Watchline header/footer.
        $socials = [
            ['network' => 'facebook', 'url' => 'https://www.facebook.com/watchlinehrvatska', 'is_active' => true, 'sort_order' => 0],
            ['network' => 'instagram', 'url' => 'https://www.instagram.com/watchline_hrvatska', 'is_active' => true, 'sort_order' => 1],
        ];

        $values = [
            'store_brand_name' => $brand,
            'store_brand_logo_path' => $logo,
            'store_brand_logo_dark_path' => $darkLogo,
            'store_brand_logo_optimized_path' => '',
            'store_brand_logo_width' => 220,
            'store_brand_logo_height' => 40,
            'store_footer_contact_title' => $brand,
            'store_footer_contact_intro' => 'Informacije o proizvodima i narudžbama.',
            'store_footer_email_sales' => $email,
            'store_footer_email_support' => $email,
            'store_footer_phone' => $phone,
            'store_footer_address' => $text('config_address'),
            'store_footer_hours' => $text('config_open'),
            'store_withdrawal_admin_email' => $email,
            'store_withdrawal_return_address' => $text('config_address'),
            'store_footer_bottom_copyright_text' => $brand.' · Sva prava pridržana.',
            'store_footer_col_1_title' => 'Proizvodi',
            'store_footer_col_1_title_translations' => ['hr' => 'Proizvodi'],
            'store_footer_col_1_category_ids' => Category::where('scope', 'catalog')->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->pluck('id')->all(),
            'store_footer_col_2_title' => 'Informacije',
            'store_footer_col_2_title_translations' => ['hr' => 'Informacije'],
            'store_footer_col_2_page_ids' => InfoPage::where('is_active', true)->orderBy('sort_order')->pluck('id')->all(),
            'store_footer_col_3_title' => 'Korisne poveznice',
            'store_footer_col_3_title_translations' => ['hr' => 'Korisne poveznice'],
            'store_footer_col_3_custom_links' => 'Moj račun|'.route('front.auth.login', absolute: false)."\nKontakt|".route('contact.create', absolute: false),
            'store_footer_bottom_link_page_ids' => InfoPage::where('is_active', true)->pluck('id')->all(),
            'store_seo_default_title' => $text('config_meta_title') ?: $brand,
            'store_seo_default_description' => $text('config_meta_description'),
            'store_seo_robots' => 'noindex,nofollow',
            'store_schema_business_name' => $brand,
            'store_schema_business_email' => $email,
            'store_schema_business_phone' => $phone,
            'store_schema_blog_author_name' => $brand,
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Besplatna GLS dostava za sve narudžbe iznad 70€',
            'store_announcement_background_color' => '#0077B5',
            'store_announcement_text_color' => '#ffffff',
            'store_benefits_bar_enabled' => false,
            'store_pricing_b2c_enabled' => true,
            'store_search_autocomplete_enabled' => true,
            'store_product_desktop_default_cols' => 5,
            'store_email_enabled' => false,
            'store_analytics_enabled' => false,
            'store_newsletter_enabled' => true,
            'store_newsletter_title' => 'Poklanjamo vam dodatnih 10% popusta',
            'store_newsletter_subtitle' => 'Prijavite se na newsletter i vaš osobni kod za popust stiže e-mailom.',
            'store_newsletter_button_label' => 'Želim svoj kod',
            'store_newsletter_consent_label' => 'Pristajem na primanje newslettera i obradu podataka za tu svrhu.',
            'store_newsletter_provider' => 'database',
            'store_newsletter_coupon_enabled' => (bool) $settings->get('store_newsletter_coupon_enabled', true),
            'store_newsletter_coupon_percent' => (float) $settings->get('store_newsletter_coupon_percent', 10),
            'msan_enabled' => false,
            'msan_price_stock_sync_enabled' => false,
            'catalog_use_blog' => BlogPost::where('is_active', true)->exists(),
            'catalog_use_attributes' => true,
            'catalog_use_options' => true,
            'catalog_use_manufacturers' => true,
            'catalog_hide_out_of_stock_products' => false,
            'store_social_facebook_url' => $socials[0]['url'],
            'store_social_instagram_url' => $socials[1]['url'],
            'store_footer_social_facebook_enabled' => true,
            'store_footer_social_instagram_enabled' => true,
            'store_footer_social_tiktok_enabled' => false,
            'store_footer_social_youtube_enabled' => false,
            NavigationMenuService::SETTINGS_KEY => $navigation,
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => [
                'container_width' => 1860, 'header_content_width' => 1860,
                'background_color' => '#0077B5', 'text_color' => '#ffffff', 'highlight_color' => '#0093DD',
            ],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => $links !== [] || $socials !== [], 'height' => 34, 'font_size' => 12,
                'background_color' => '#EDF6FC', 'text_color' => '#00699E', 'border_color' => '#DCEBF5',
                'links' => $links, 'socials' => $socials,
            ],
        ];

        if ($navigationOnly) {
            $settings->putMany(array_intersect_key($values, array_flip([
                NavigationMenuService::SETTINGS_KEY, NavigationMenuService::APPEARANCE_SETTINGS_KEY,
                NavigationMenuService::TOP_BAR_SETTINGS_KEY, 'store_announcement_background_color', 'store_announcement_text_color',
                'store_brand_logo_path', 'store_brand_logo_dark_path', 'store_brand_logo_optimized_path',
                'store_brand_logo_width', 'store_brand_logo_height',
                'store_social_facebook_url', 'store_social_instagram_url',
                'store_footer_social_facebook_enabled', 'store_footer_social_instagram_enabled',
            ])));
            $this->info('Watchline category navigation and palette updated.');

            return self::SUCCESS;
        }
        $settings->putMany($values);

        $this->call('watchline:configure-home');

        $this->info('Watchline preview configured with imported catalog and public shop details.');

        return self::SUCCESS;
    }
}
