<?php

namespace App\Console\Commands;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Pricing\ProductPricePresentationService;
use App\Support\Media\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LibermediaConfigureHome extends Command
{
    protected $signature = 'libermedia:configure-home {--force : Replace already configured Liber Media modules} {--benefits-only : Configure only the hero benefits module}';

    protected $description = 'Create admin-managed Liber Media homepage modules in the local preview';

    public function handle(GuestStorefrontCache $storefrontCache): int
    {
        if (! app()->environment('local') || config('app.name') !== 'Libermedia') {
            $this->error('Restricted to the local Libermedia preview.');

            return self::FAILURE;
        }

        $definitions = [
            ['code' => 'libermedia-home-benefits', 'title' => 'Liber Media – prednosti kupnje', 'is_products' => false, 'placement' => 'home.hero_benefits', 'sort_order' => 10],
            ['code' => 'libermedia-home-campaigns', 'title' => 'Liber Media – odabrana ponuda', 'is_products' => false, 'placement' => 'home.before_products', 'sort_order' => 10],
            ['code' => 'libermedia-home-kindergarten', 'title' => 'Oprema i namještaj za vrtiće', 'is_products' => false, 'placement' => 'home.after_products', 'sort_order' => 10],
            ['code' => 'libermedia-home-creative-products', 'title' => 'Za male graditelje i velike ideje', 'is_products' => true, 'placement' => 'home.after_products', 'sort_order' => 20],
            ['code' => 'libermedia-home-promotions', 'title' => 'Akcije i popusti', 'is_products' => true, 'is_promotions' => true, 'placement' => 'home.bottom', 'sort_order' => 5],
            ['code' => 'libermedia-home-assurance', 'title' => 'Pomno birano za igru i učenje', 'is_products' => false, 'placement' => 'home.bottom', 'sort_order' => 10],
        ];
        if ($this->option('benefits-only')) {
            $definitions = array_filter($definitions, fn (array $definition): bool => $definition['code'] === 'libermedia-home-benefits');
        }
        $updated = 0;

        DB::transaction(function () use ($definitions, &$updated): void {
            foreach ($definitions as $definition) {
                $block = ContentBlock::query()->firstOrNew(['code' => $definition['code']]);

                // Preserve changes made later through the content administration.
                if ($block->exists && ! $this->option('force')) {
                    $this->line('Kept existing module: '.$definition['code']);

                    continue;
                }

                $isProducts = $definition['is_products'];
                $isPromotions = (bool) ($definition['is_promotions'] ?? false);
                $block->fill([
                    'name' => $definition['title'],
                    'type' => $isProducts ? 'products_carousel' : 'rich_text',
                    'is_active' => true,
                    'payload' => [],
                ])->save();

                $block->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => $definition['title'],
                    'subtitle' => $isPromotions ? 'Odabrani proizvodi po povoljnijim cijenama.' : ($isProducts ? 'Kocke, slagalice i igre koje pokreću maštu.' : null),
                    // Native block administration edits each instance Blade template.
                    'body_html' => null,
                    'cta_label' => $isPromotions ? 'Sve akcije' : ($isProducts ? 'Sve didaktičke igračke' : null),
                    'cta_url' => $isPromotions ? '/akcije' : ($isProducts ? '/category/didakticke-igracke' : null),
                    'payload' => [],
                ]);

                $block->slots()->updateOrCreate([
                    'placement' => $definition['placement'],
                    'frontend_variant' => 'all',
                    'target_type' => null,
                    'target_ref' => null,
                ], [
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                    'starts_at' => null,
                    'ends_at' => null,
                ]);

                if ($isProducts) {
                    $products = $isPromotions ? $this->promotionProducts() : $this->creativeProducts();
                    if ($isPromotions && $products->isEmpty()) {
                        throw new \RuntimeException('At least one current public promotion with stock and a usable main image is required.');
                    }
                    if (! $isPromotions && $products->count() < 8) {
                        throw new \RuntimeException('Eight active, in-stock toy products with usable main images are required.');
                    }

                    $block->items()->delete();
                    foreach ($products as $index => $product) {
                        $block->items()->create([
                            'item_type' => 'product',
                            'item_id' => $product->id,
                            'sort_order' => $index * 10,
                        ]);
                    }
                }

                $updated++;
            }
        });

        if ($updated > 0) {
            ContentBlockResolver::bumpCacheVersion();
            $storefrontCache->invalidate();
        }

        $this->info("Liber Media homepage modules saved: {$updated}. Edit them under Content > Blocks and Slots.");

        return self::SUCCESS;
    }

    /** @return Collection<int, Product> */
    private function promotionProducts(): Collection
    {
        // Public B2C actions are authoritative; draft B2B catalogues are not public offers.
        $productIds = CatalogAction::query()
            ->active()
            ->availableForAudience(null)
            ->where('scope', CatalogAction::SCOPE_PRODUCT)
            ->where('target_type', CatalogAction::TARGET_PRODUCT)
            ->whereIn('type', [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])
            ->where('discount_value', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('coupon_code')->orWhere('coupon_code', ''))
            ->with('targets')
            ->get()
            ->flatMap(fn (CatalogAction $action) => $action->targets->where('target_type', CatalogAction::TARGET_PRODUCT)->pluck('target_id'))
            ->unique()->values();
        $prices = app(ProductPricePresentationService::class);
        $candidates = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->where('stock_qty', '>', 0)
            ->whereHas('media', fn (Builder $query) => $query->where('collection_name', 'product_main'))
            ->with(['media', 'categories', 'manufacturer', 'taxRate'])
            ->orderBy('id')->get()
            ->filter(fn (Product $product): bool => $product->media->where('collection_name', 'product_main')
                ->contains(fn ($media): bool => MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w'])))
            ->filter(fn (Product $product): bool => (bool) ($prices->forProduct($product)['has_promotional_discount'] ?? false));
        $byManufacturer = $candidates->groupBy('manufacturer_id');
        $selected = collect();

        while ($selected->count() < 8 && $byManufacturer->isNotEmpty()) {
            foreach ($byManufacturer as $manufacturer => $products) {
                $selected->push($products->shift());
                if ($products->isEmpty()) {
                    $byManufacturer->forget($manufacturer);
                }
                if ($selected->count() === 8) {
                    break;
                }
            }
        }

        return $selected->unique('id')->values();
    }

    /** @return Collection<int, Product> */
    private function creativeProducts(): Collection
    {
        $roots = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', 'hr')
                ->whereIn('slug', ['didakticke-igracke', 'kocke-za-djecu', 'robot-i-stem-igracke']))
            ->get();
        $categoryIds = $roots->flatMap(fn (Category $category) => Category::descendantsAndSelf($category->id)->pluck('id'))
            ->unique()->values();
        $query = Product::query()
            ->where('is_active', true)
            ->where('stock_qty', '>', 0)
            ->whereHas('categories', fn (Builder $categories) => $categories->whereIn('categories.id', $categoryIds))
            ->whereHas('media', fn (Builder $media) => $media->where('collection_name', 'product_main'))
            ->with(['media' => fn ($media) => $media->where('collection_name', 'product_main')]);
        $hasMainImage = static fn (Product $product): bool => $product->media
            ->contains(fn ($media): bool => MediaUrl::hasUsableSource($media, ['card_720w', 'card_480w', 'card_320w']));

        // Original product SKUs survive re-imports; avoid database-specific IDs.
        $curatedSkus = ['50005', '50324', '2102', '900000089', 'HP038', '7424', '50900', '50422'];
        $curated = (clone $query)->whereIn('sku', $curatedSkus)->orderBy('id')->get()
            ->filter($hasMainImage)->keyBy('sku');
        $selected = collect($curatedSkus)->map(fn (string $sku) => $curated->get($sku))->filter()->unique('id')->values();

        if ($selected->count() < 8) {
            $fallback = (clone $query)->whereNotIn('id', $selected->pluck('id'))->orderBy('id')->get()
                ->filter($hasMainImage)->groupBy('manufacturer_id');

            // Alternate suppliers when a curated toy is no longer available.
            while ($selected->count() < 8 && $fallback->isNotEmpty()) {
                foreach ($fallback as $manufacturer => $products) {
                    $selected->push($products->shift());
                    if ($products->isEmpty()) {
                        $fallback->forget($manufacturer);
                    }
                    if ($selected->count() === 8) {
                        break;
                    }
                }
            }
        }

        return $selected->unique('id')->values()->take(8);
    }
}
