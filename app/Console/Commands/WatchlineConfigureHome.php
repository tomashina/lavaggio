<?php

namespace App\Console\Commands;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Support\WatchlineHomeContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WatchlineConfigureHome extends Command
{
    protected $signature = 'watchline:configure-home {--force : Replace previously configured Watchline homepage modules}';

    protected $description = 'Retain the copied Liber Media homepage modules using only Watchline content and products';

    public function handle(WatchlineHomeContent $content, GuestStorefrontCache $storefrontCache): int
    {
        if (! app()->environment('local') || strcasecmp((string) config('app.name'), 'Watchline') !== 0) {
            $this->error('Restricted to the local Watchline preview.');

            return self::FAILURE;
        }
        // Same six modules, placements, templates and ordering as the source project.
        $definitions = [
            ['code' => 'watchline-home-benefits', 'title' => 'Watchline – prednosti kupnje', 'placement' => 'home.hero_benefits', 'order' => 10],
            ['code' => 'watchline-home-campaigns', 'title' => 'Watchline – odabrana ponuda', 'placement' => 'home.before_products', 'order' => 10],
            ['code' => 'watchline-home-kindergarten', 'title' => 'Detalji koji prate vaš stil', 'placement' => 'home.after_products', 'order' => 10],
            ['code' => 'watchline-home-creative-products', 'title' => 'Pronađite svoj novi omiljeni model', 'placement' => 'home.after_products', 'order' => 20, 'products' => true],
            ['code' => 'watchline-home-assurance', 'title' => 'Vaš izbor. Naša podrška.', 'placement' => 'home.bottom', 'order' => 10],
            ['code' => 'watchline-home-promotions', 'title' => 'Akcije i popusti', 'placement' => 'home.bottom', 'order' => 5, 'products' => true, 'promotions' => true],
        ];
        $selected = $content->products();
        $promotionIds = CatalogAction::active()->where('scope', CatalogAction::SCOPE_PRODUCT)->where('audience_type', CatalogAction::AUDIENCE_ALL)
            ->where('target_type', CatalogAction::TARGET_PRODUCT)->with('targets')->get()->flatMap->targets->pluck('target_id')->unique();
        $promotions = Product::whereIn('id', $promotionIds)->where('is_active', true)->where('stock_qty', '>', 0)->orderBy('id')->limit(8)->get();
        $updated = 0;
        DB::transaction(function () use ($definitions, $selected, $promotions, &$updated): void {
            foreach ($definitions as $definition) {
                $block = ContentBlock::firstOrNew(['code' => $definition['code']]);
                if ($block->exists && ! $this->option('force')) {
                    continue;
                }
                $isProducts = (bool) ($definition['products'] ?? false);
                $isPromotions = (bool) ($definition['promotions'] ?? false);
                $block->fill(['name' => $definition['title'], 'type' => $isProducts ? 'products_carousel' : 'rich_text', 'is_active' => true, 'payload' => []])->save();
                $block->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => $definition['title'],
                    'subtitle' => $isPromotions ? 'Odabrani proizvodi po povoljnijim cijenama.' : ($isProducts ? 'Satovi, nakit i dodaci iz naše ponude.' : null),
                    'body_html' => null, 'cta_label' => $isPromotions ? 'Sve akcije' : ($isProducts ? 'Svi proizvodi' : null),
                    'cta_url' => $isPromotions ? '/akcije' : ($isProducts ? '/shop' : null), 'payload' => [],
                ]);
                $block->slots()->updateOrCreate(['placement' => $definition['placement'], 'frontend_variant' => 'all', 'target_type' => null, 'target_ref' => null], [
                    'sort_order' => $definition['order'], 'is_active' => true, 'starts_at' => null, 'ends_at' => null,
                ]);
                if ($isProducts) {
                    $block->items()->delete();
                    foreach ($isPromotions ? $promotions : $selected as $index => $product) {
                        $block->items()->create(['item_type' => 'product', 'item_id' => $product->id, 'sort_order' => $index * 10]);
                    }
                }
                $updated++;
            }
        });
        ContentBlockResolver::bumpCacheVersion();
        $storefrontCache->invalidate();
        $this->info('Watchline homepage modules copied from the Liber Media layout: '.$updated.'.');

        return self::SUCCESS;
    }
}
