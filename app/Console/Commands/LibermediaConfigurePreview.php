<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Models\Settings\Local\PaymentMethod;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use App\Support\LibermediaPaymentDescription;
use Illuminate\Console\Command;

class LibermediaConfigurePreview extends Command
{
    protected $signature = 'libermedia:configure-preview {--navigation-only : Update only admin-managed navigation, contacts and social links}';

    protected $description = 'Apply the Herrera visual foundation with Liber Media content and branding';

    public function handle(SystemSettingsService $settings): int
    {
        if (! app()->environment('local') || config('app.name') !== 'Libermedia') {
            $this->error('Restricted to the local Libermedia preview.');

            return self::FAILURE;
        }

        $aboutId = InfoPage::whereHas('translations', fn ($query) => $query->where('locale', 'hr')->where('slug', 'o-nama'))->value('id');
        $navigation = [
            ['type' => 'catalog', 'label' => 'Sve kategorije', 'url' => '/shop', 'is_active' => true, 'show_dropdown' => true, 'sort_order' => 0],
            ['type' => 'custom', 'label' => 'Brandovi', 'url' => '/brendovi', 'is_active' => true, 'sort_order' => 1],
            ['type' => 'custom', 'label' => 'Akcije', 'url' => '/akcije', 'is_active' => true, 'sort_order' => 2],
            // Bundle offers are managed locally.
            ['type' => 'custom', 'label' => 'Posebna ponuda', 'url' => '/bundle-artikli', 'is_active' => true, 'sort_order' => 3],
            ['type' => 'blog', 'label' => 'Blog', 'is_active' => true, 'sort_order' => 4],
        ];
        if ($aboutId) {
            $navigation[] = ['type' => 'page', 'label' => 'O nama', 'page_id' => $aboutId, 'is_active' => true, 'sort_order' => 5];
        }
        $navigation[] = ['type' => 'contact', 'label' => 'Kontakt', 'is_active' => true, 'sort_order' => 6];
        $navigation[] = ['type' => 'custom', 'label' => 'Tražiš poklon?', 'url' => '/savjeti-za-poklone', 'icon' => 'gift', 'align_right' => true, 'is_active' => true, 'sort_order' => 7];
        $navigation[] = ['type' => 'custom', 'label' => 'Poklon bon', 'url' => '/poklon-bon', 'icon' => 'ticket', 'align_right' => true, 'is_active' => true, 'sort_order' => 8];

        $navigationSettings = [
            NavigationMenuService::SETTINGS_KEY => $navigation,
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => true, 'height' => 34, 'font_size' => 12,
                'background_color' => '#EDF3EC', 'text_color' => '#245B42', 'border_color' => '#DCE7DC',
                'links' => [
                    ['label' => 'info@liber-media.hr', 'url' => 'mailto:info@liber-media.hr', 'is_active' => true, 'sort_order' => 0],
                    ['label' => '+385 95 388 8535', 'url' => 'tel:+385953888535', 'is_active' => true, 'sort_order' => 1],
                ],
                'socials' => [
                    ['network' => 'facebook', 'url' => 'https://www.facebook.com/libermedia01', 'is_active' => true, 'sort_order' => 0],
                    ['network' => 'instagram', 'url' => 'https://www.instagram.com/liber_media01/', 'is_active' => true, 'sort_order' => 1],
                ],
            ],
            'store_social_facebook_url' => 'https://www.facebook.com/libermedia01',
            'store_social_instagram_url' => 'https://www.instagram.com/liber_media01/',
            'store_footer_social_facebook_enabled' => true,
            'store_footer_social_instagram_enabled' => true,
        ];

        if ($this->option('navigation-only')) {
            $settings->putMany($navigationSettings);
            $this->info('Liber Media navigation, contacts and social links saved in admin settings.');

            return self::SUCCESS;
        }

        $settings->putMany([
            'store_brand_logo_path' => 'assets/brand/libermedia-logo.svg',
            'store_brand_logo_dark_path' => 'assets/brand/libermedia-logo-dark.svg',
            'store_brand_logo_width' => 220, 'store_brand_logo_height' => 40,
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Liber Media · Didaktičke igračke i oprema za vrtiće',
            'store_announcement_background_color' => '#FF4D00', 'store_announcement_text_color' => '#ffffff',
            'store_benefits_bar_enabled' => false,
            'store_search_autocomplete_enabled' => true,
            'store_pricing_b2c_enabled' => true,
            'store_newsletter_title' => 'Novosti iz svijeta igre i učenja',
            'store_newsletter_subtitle' => 'Prijavite se za novosti iz Liber Media ponude.',
            'store_newsletter_button_label' => 'Prijavi se',
            'store_newsletter_consent_label' => 'Pristajem na primanje newslettera i obradu podataka za tu svrhu.',
            'store_footer_contact_intro' => 'Informacije o proizvodima i narudžbama.',
            'store_footer_col_1_title_translations' => ['hr' => 'Proizvodi'],
            'store_footer_col_2_title_translations' => ['hr' => 'Informacije'],
            'store_footer_col_3_title' => 'Korisne poveznice',
            'store_footer_col_3_title_translations' => ['hr' => 'Korisne poveznice'],
            'store_footer_col_3_custom_links' => "Moj račun|/auth/login\nBlog|/blog\nKontakt|/contact",
            'store_footer_social_tiktok_enabled' => false,
            'store_footer_social_youtube_enabled' => false,
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => [
                'container_width' => 1860, 'header_content_width' => 1860,
                'background_color' => '#245B42', 'text_color' => '#ffffff', 'highlight_color' => '#FF4D00',
            ],
        ] + $navigationSettings);
        foreach (PaymentMethod::query()->whereIn('code', ['bank', 'bank_transfer'])->get() as $paymentMethod) {
            $paymentMethod->description = LibermediaPaymentDescription::forConsumers((string) $paymentMethod->description);
            $paymentSettings = (array) $paymentMethod->settings;
            if (isset($paymentSettings['bank_instructions'])) {
                $paymentSettings['bank_instructions'] = LibermediaPaymentDescription::forConsumers((string) $paymentSettings['bank_instructions']);
                $paymentMethod->settings = $paymentSettings;
            }
            if ($paymentMethod->isDirty()) {
                $paymentMethod->save();
            }
        }
        // The legacy shop has no category pictures. Use an existing product
        // photo from each category, without changing the original source data.
        $profile = config('media_profiles.models.'.Category::class);
        foreach ($profile['collections'] as &$collection) {
            $collection['conversions'] = [];
        }
        unset($collection);
        config(['media_profiles.models.'.Category::class => $profile]);
        foreach (Category::where('scope', 'catalog')->whereNull('parent_id')->with('media')->get() as $category) {
            if ($category->getFirstMedia('category_icon') || $category->getFirstMedia('category_banner')) {
                continue;
            }
            $ids = Category::descendantsAndSelf($category->id)->pluck('id');
            $product = Product::where('is_active', true)->whereHas('media', fn ($q) => $q->where('collection_name', 'product_main'))
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $ids))
                ->orderByRaw('CASE WHEN stock_qty > 0 THEN 0 ELSE 1 END')->orderBy('id')->first();
            $media = $product?->getFirstMedia('product_main');
            if ($media && is_file($media->getPath())) {
                $category->addMedia($media->getPath())->preservingOriginal()
                    ->withCustomProperties(['preview_product_id' => $product->id])
                    ->toMediaCollection('category_icon', 'public');
            }
        }
        $this->info('Liber Media preview configured using the Herrera layout.');

        return self::SUCCESS;
    }
}
