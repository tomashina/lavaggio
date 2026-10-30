<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category\Category;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class LavaggioConfigureHome extends Command
{
    protected $signature = 'lavaggio:configure-home {--force : Replace existing Lavaggio homepage widget settings}';

    protected $description = 'Create editable, ordered Lavaggio homepage widgets in the local preview';

    public function handle(GuestStorefrontCache $storefrontCache): int
    {
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio') {
            $this->error('Restricted to the local Lavaggio preview.');

            return self::FAILURE;
        }

        $systemSettings = app(SystemSettingsService::class);
        $categoryImages = app(NavigationMenuService::class)->categoryImages();
        $categorySettings = [];
        $descriptions = [
            'lavaggio-deterdzenti' => 'Za bijelo, šareno, crno i osjetljivo rublje.',
            'lavaggio-uklanjanje-mrlja' => 'Pronađite pomoć za tvrdokorne mrlje na rublju.',
            'lavaggio-mirisi-i-njega-rublja' => 'Omekšivači i mirisi za svježinu nakon svakog pranja.',
            'lavaggio-higijena-i-odrzavanje' => 'Njega rublja započinje čistom perilicom.',
            'lavaggio-pribor-i-dodaci-za-rublje' => 'Praktični dodaci za pranje, sušenje i njegu odjeće.',
        ];
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->whereNull('parent_id')
            ->currentlyVisible()->with('translations')->orderBy('sort_order')->orderBy('id')->limit(5)->get();
        foreach ($categories as $index => $category) {
            $translation = $category->translations->firstWhere('locale', 'hr') ?? $category->translations->first();
            $key = 'card_'.($index + 1).'_';
            $image = $categoryImages[(int) $category->id] ?? [];
            $categorySettings += [
                $key.'title' => $translation?->name ?: $category->code,
                $key.'description' => $descriptions[$category->code] ?? '',
                $key.'url' => $translation?->slug ? route('categories.show', $translation->slug, false) : '/shop',
                $key.'image' => is_file(public_path('assets/lavaggio/home/categories/'.str_replace('lavaggio-', '', $category->code).'.webp'))
                    ? '/assets/lavaggio/home/categories/'.str_replace('lavaggio-', '', $category->code).'.webp'
                    : (string) ($image['image_path'] ?? ''),
                $key.'image_alt' => (string) ($translation?->name ?? ''),
            ];
        }
        $categorySettings += [
            'card_6_title' => 'Kupi u kompletu i uštedi',
            'card_6_description' => 'Otkrijte odabrane komplete za pranje i njegu rublja.',
            'card_6_url' => route('bundles.index', absolute: false),
            'card_6_eyebrow' => 'VIŠE NJEGE, VIŠE UŠTEDE',
            'card_6_cta_label' => 'Pogledajte ponudu',
            'card_6_icon' => 'gift',
        ];
        $definitions = [
            ['code' => 'lavaggio-home-campaigns', 'name' => 'Početna — webshop i praonica', 'type' => 'lavaggio_campaigns', 'title' => $systemSettings->get('lavaggio_home_title', 'Njega rublja s potpisom Lavaggio.'), 'subtitle' => $systemSettings->get('lavaggio_home_description', 'Iskustvo profesionalne praonice u vašem domu. Pažljivo odabrana njega za rublje koje volite.')],
            ['code' => 'lavaggio-home-categories', 'name' => 'Početna — kategorije i kompleti', 'type' => 'lavaggio_categories', 'settings' => $categorySettings],
            ['code' => 'lavaggio-home-latest', 'name' => 'Početna — novo u ponudi', 'type' => 'lavaggio_products', 'title' => 'Novo u ponudi', 'cta_label' => 'Svi proizvodi', 'cta_url' => '/shop', 'settings' => ['source' => 'latest'], 'limit' => 12],
            ['code' => 'lavaggio-home-brands', 'name' => 'Početna — brandovi s logotipima', 'type' => 'lavaggio_brands'],
            ['code' => 'lavaggio-home-promotions', 'name' => 'Početna — artikli na akciji', 'type' => 'lavaggio_products', 'title' => 'Artikli na akciji', 'cta_label' => 'Sve akcije', 'cta_url' => '/akcije', 'settings' => ['eyebrow' => 'ODABRANA AKCIJSKA PONUDA', 'source' => 'promotions', 'promotions_only' => true, 'product_sort' => 'largest_discount'], 'limit' => 12],
            ['code' => 'lavaggio-home-reviews', 'name' => 'Početna — komentari kupaca', 'type' => 'lavaggio_reviews', 'cta_url' => route('reviews.index', absolute: false), 'limit' => 12],
            ['code' => 'lavaggio-home-blog', 'name' => 'Početna — zadnje s bloga i iz TV studija', 'type' => 'lavaggio_blog', 'limit' => 4],
        ];
        $created = 0;
        DB::transaction(function () use ($definitions, &$created): void {
            foreach ($definitions as $index => $definition) {
                $block = ContentBlock::firstOrNew(['code' => $definition['code']]);
                if ($block->exists && ! $this->option('force')) {
                    continue;
                }
                $defaults = WatchlineWidgetSettings::defaults($definition['type']);
                $block->fill(['name' => $definition['name'], 'type' => $definition['type'], 'is_active' => true,
                    'payload' => array_merge((array) $block->payload, ['lavaggio_widget_version' => 1])])->save();
                $settings = array_replace(Arr::except($defaults, ['title', 'subtitle', 'cta_label', 'cta_url']), $definition['settings'] ?? []);
                $payload = ['watchline' => $settings, 'items_limit' => $definition['limit'] ?? 12, 'blog_source' => 'latest'];
                $block->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => $definition['title'] ?? $defaults['title'] ?? $definition['name'],
                    'subtitle' => $definition['subtitle'] ?? $defaults['subtitle'] ?? null,
                    'body_html' => null,
                    'cta_label' => $definition['cta_label'] ?? $defaults['cta_label'] ?? null,
                    'cta_url' => $definition['cta_url'] ?? $defaults['cta_url'] ?? null,
                    'payload' => $payload,
                ]);
                $block->slots()->updateOrCreate(['placement' => 'home.content', 'frontend_variant' => 'all'], [
                    'target_type' => null, 'target_ref' => null, 'sort_order' => ($index + 1) * 10,
                    'is_active' => true, 'starts_at' => null, 'ends_at' => null,
                ]);
                $created++;
            }
        });
        ContentBlockResolver::bumpCacheVersion();
        $storefrontCache->invalidate();
        $this->info('Editable Lavaggio homepage widgets configured: '.$created.'. Existing edits are preserved unless --force is used.');

        return self::SUCCESS;
    }
}
