<?php

namespace App\Console\Commands;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Front\WatchlineHeroService;
use App\Support\WatchlineHomeContent;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class WatchlineConfigureHome extends Command
{
    protected $signature = 'watchline:configure-home {--force : Replace previously configured Watchline widget content}';

    protected $description = 'Move the local Watchline homepage into editable, ordered widgets without changing its design';

    public function handle(WatchlineHomeContent $content, GuestStorefrontCache $storefrontCache): int
    {
        if (! app()->environment('local') || strcasecmp((string) config('app.name'), 'Watchline') !== 0) {
            $this->error('Restricted to the local Watchline preview.');

            return self::FAILURE;
        }

        $cluse = app(WatchlineHeroService::class)->products();
        $guess = $content->guessProducts();
        $cluseTranslation = $cluse->first()?->manufacturer?->translations->firstWhere('locale', 'hr');
        $cluseUrl = $cluseTranslation?->slug ? route('manufacturers.show', ['slug' => $cluseTranslation->slug], false) : '/category/zenski-satovi-cluse';
        $selected = $content->products();
        $promotionIds = CatalogAction::active()->where('scope', CatalogAction::SCOPE_PRODUCT)->where('audience_type', CatalogAction::AUDIENCE_ALL)
            ->where('target_type', CatalogAction::TARGET_PRODUCT)->with('targets')->get()->flatMap->targets->pluck('target_id')->unique();
        $promotions = Product::whereIn('id', $promotionIds)->where('is_active', true)->where('stock_qty', '>', 0)->orderBy('id')->limit(8)->get();
        $brands = Manufacturer::query()->where('is_active', true)->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('id')->get();
        $definitions = [
            ['code' => 'watchline-home-campaigns', 'name' => 'Početna — kategorijski banneri', 'type' => 'watchline_campaigns', 'title' => 'Watchline – odabrana ponuda'],
            ['code' => 'watchline-home-cluse', 'name' => 'Početna — CLUSE satovi', 'type' => 'watchline_products', 'title' => 'CLUSE, po izboru naših kupaca', 'cta_label' => 'Svi CLUSE satovi', 'cta_url' => $cluseUrl, 'settings' => ['eyebrow' => 'ŽENSKI SATOVI', 'source' => 'cluse_bestsellers'], 'items' => $cluse, 'limit' => 8],
            ['code' => 'watchline-home-guess', 'name' => 'Početna — GUESS, novo u ponudi', 'type' => 'watchline_products', 'title' => 'GUESS satovi', 'cta_label' => 'Svi GUESS satovi', 'cta_url' => $content->guessCategoryUrl(), 'settings' => ['eyebrow' => 'Novo u ponudi', 'source' => 'guess_newest'], 'items' => $guess, 'limit' => 12],
            ['code' => 'watchline-home-locations', 'name' => 'Početna — servis i trgovina', 'type' => 'watchline_locations', 'title' => 'Tu smo i uživo.', 'subtitle' => 'Za vaš novi sat. I sve one koje već volite.'],
            ['code' => 'watchline-home-creative-products', 'name' => 'Početna — izdvojeni proizvodi', 'type' => 'watchline_products', 'title' => 'Pronađite svoj novi omiljeni model', 'cta_label' => 'Svi proizvodi', 'cta_url' => '/shop', 'settings' => ['eyebrow' => 'IZ WATCHLINE PONUDE', 'source' => 'manual'], 'items' => $selected, 'limit' => 8, 'preserve_items' => true],
            ['code' => 'watchline-home-brands', 'name' => 'Početna — brandovi', 'type' => 'watchline_brands', 'title' => 'Brandovi iz naše ponude', 'cta_label' => 'Svi brandovi', 'cta_url' => '/brendovi', 'settings' => ['eyebrow' => 'ODABRANI BRANDOVI', 'visible_count' => 6, 'source' => 'manual'], 'items' => $brands, 'item_type' => 'manufacturer'],
            ['code' => 'watchline-home-promotions', 'name' => 'Početna — akcije i popusti', 'type' => 'watchline_products', 'title' => 'Akcije i popusti', 'cta_label' => 'Sve akcije', 'cta_url' => '/akcije', 'settings' => ['eyebrow' => 'ODABRANA AKCIJSKA PONUDA', 'source' => 'promotions', 'product_sort' => 'largest_discount', 'promotions_only' => true], 'items' => $promotions, 'limit' => 8, 'preserve_items' => true],
            ['code' => 'watchline-home-assurance', 'name' => 'Početna — podrška kupcima', 'type' => 'watchline_assurance', 'title' => 'Vaš izbor. Naša podrška.', 'active' => false],
            ['code' => 'watchline-home-reviews', 'name' => 'Početna — iskustva kupaca', 'type' => 'watchline_reviews', 'title' => 'Što kažu naši kupci', 'subtitle' => '', 'cta_label' => 'Sva iskustva kupaca', 'cta_url' => route('reviews.index', absolute: false), 'settings' => ['eyebrow' => 'ISKUSTVA KUPACA'], 'limit' => 50],
            ['code' => 'watchline-home-blog', 'name' => 'Početna — blog', 'type' => 'watchline_blog', 'title' => 'Novosti i inspiracija', 'cta_label' => 'Vidi sve', 'cta_url' => '/blog', 'settings' => ['eyebrow' => 'WATCHLINE BLOG'], 'limit' => 5],
            ['code' => 'watchline-home-benefits', 'name' => 'Podnožje — prednosti kupnje', 'type' => 'watchline_benefits', 'title' => 'Prednosti kupnje', 'placement' => 'footer.benefits'],
        ];
        $updated = 0;
        DB::transaction(function () use ($definitions, &$updated): void {
            $oldBanner = ContentBlock::where('code', 'watchline-home-kindergarten')->first();
            if ($oldBanner) {
                $oldBanner->update(['is_active' => false]);
                $oldBanner->slots()->update(['is_active' => false]);
            }
            foreach ($definitions as $index => $definition) {
                $block = ContentBlock::firstOrNew(['code' => $definition['code']]);
                if ($block->exists && (int) data_get($block->payload, 'watchline_widget_version') === 1 && ! $this->option('force')) {
                    continue;
                }
                $wasExisting = $block->exists;
                $isActive = $definition['active'] ?? ($wasExisting && ! $this->option('force') ? $block->is_active : true);
                $payload = is_array($block->payload) ? $block->payload : [];
                unset($payload['view_override']);
                $payload['watchline_widget_version'] = 1;
                $block->fill(['name' => $definition['name'], 'type' => $definition['type'], 'is_active' => $isActive, 'payload' => $payload])->save();
                $defaults = WatchlineWidgetSettings::defaults($definition['type']);
                $settings = array_replace(Arr::except($defaults, ['title', 'subtitle', 'cta_label', 'cta_url']), $definition['settings'] ?? []);
                $translationPayload = ['watchline' => $settings];
                if (isset($definition['limit'])) {
                    $translationPayload['items_limit'] = $definition['limit'];
                }
                if ($definition['type'] === 'watchline_blog') {
                    $translationPayload['blog_source'] = 'latest';
                }
                $block->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => $definition['title'] ?? ($defaults['title'] ?? null),
                    'subtitle' => $definition['subtitle'] ?? ($defaults['subtitle'] ?? null),
                    'body_html' => null,
                    'cta_label' => $definition['cta_label'] ?? ($defaults['cta_label'] ?? null),
                    'cta_url' => $definition['cta_url'] ?? ($defaults['cta_url'] ?? null),
                    'payload' => $translationPayload,
                ]);
                $slot = $block->slots()->orderBy('id')->first() ?? $block->slots()->make();
                $slot->fill([
                    'placement' => $definition['placement'] ?? 'home.content', 'frontend_variant' => 'all',
                    'target_type' => null, 'target_ref' => null, 'sort_order' => ($index + 1) * 10,
                    'is_active' => $isActive, 'starts_at' => null, 'ends_at' => null,
                ])->save();
                $block->slots()->where('id', '!=', $slot->id)->delete();
                if (isset($definition['items']) && (! ($definition['preserve_items'] ?? false) || ! $wasExisting || $this->option('force'))) {
                    $block->items()->delete();
                    foreach ($definition['items'] as $itemIndex => $item) {
                        $block->items()->create(['item_type' => $definition['item_type'] ?? 'product', 'item_id' => $item->id, 'sort_order' => $itemIndex * 10]);
                    }
                }
                $updated++;
            }
        });
        ContentBlockResolver::bumpCacheVersion();
        $storefrontCache->invalidate();
        $this->info('Editable Watchline homepage widgets configured: '.$updated.'.');

        return self::SUCCESS;
    }
}
