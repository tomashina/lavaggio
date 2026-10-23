<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockTranslation;
use App\Services\Content\WatchlineWidgetProducts;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\WatchlineHomeContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlineWidgetProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
    }

    public function test_manual_promotion_filter_finds_the_first_eligible_selection_before_applying_the_limit(): void
    {
        $fullPrice = $this->product('manual-full-price', '2026-10-20 10:00:00');
        $discounted = $this->product('manual-discounted', '2026-10-19 10:00:00');
        $this->discount($discounted);
        [$block, $translation] = $this->widget('manual', 1, promotionsOnly: true);

        $products = app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect([$fullPrice, $discounted]));

        $this->assertSame([$discounted->id], $products->pluck('id')->all());
    }

    public function test_latest_promotion_selection_skips_newer_missing_images_and_full_price_products(): void
    {
        $eligible = $this->product('older-eligible', '2026-10-01 10:00:00');
        $this->discount($eligible);
        foreach (range(1, 50) as $index) {
            $this->product('newer-full-price-'.$index, '2026-10-20 10:00:00');
        }
        $missingImage = $this->product('newest-missing-image', '2026-10-21 10:00:00', image: false);
        $this->discount($missingImage);
        [$block, $translation] = $this->widget('latest', 1, promotionsOnly: true);

        $products = app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect());

        $this->assertSame([$eligible->id], $products->pluck('id')->all());
    }

    public function test_guess_source_honors_twenty_items_even_after_a_smaller_selection_in_the_same_request(): void
    {
        $guess = Manufacturer::create(['code' => 'widget-test-guess', 'is_active' => true]);
        $guess->translations()->create(['locale' => 'hr', 'name' => 'GUESS', 'slug' => 'guess-satovi']);
        $expected = [];
        foreach (range(1, 20) as $index) {
            $product = $this->product('guess-'.$index, sprintf('2026-10-%02d 10:00:00', $index), $guess);
            $expected[] = $product->id;
        }
        $this->product('guess-inactive', '2026-10-22 10:00:00', $guess, attributes: ['is_active' => false]);
        $this->product('guess-no-stock', '2026-10-22 10:00:00', $guess, attributes: ['stock_qty' => 0]);
        $this->assertCount(12, app(WatchlineHomeContent::class)->guessProducts(12));
        [$block, $translation] = $this->widget('guess_newest', 20);

        $products = app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect());

        $this->assertCount(20, $products);
        $this->assertSame(array_reverse($expected), $products->pluck('id')->all());
    }

    public function test_category_and_brand_lists_intersect_while_each_list_accepts_any_selected_value_and_descendants(): void
    {
        $women = $this->category('filter-women');
        $child = $this->category('filter-women-child', $women);
        $men = $this->category('filter-men');
        $outside = $this->category('filter-outside');
        $firstBrand = $this->manufacturer('filter-brand-first');
        $secondBrand = $this->manufacturer('filter-brand-second');
        $wrongBrand = $this->manufacturer('filter-brand-wrong');
        $womenWatch = $this->product('filter-women-watch', '2026-10-10 10:00:00', $firstBrand);
        $womenWatch->categories()->attach([$women->id, $child->id]);
        $childWatch = $this->product('filter-child-watch', '2026-10-11 10:00:00', $secondBrand);
        $childWatch->categories()->attach($child);
        $menWatch = $this->product('filter-men-watch', '2026-10-12 10:00:00', $secondBrand);
        $menWatch->categories()->attach($men);
        $this->product('filter-wrong-brand', '2026-10-22 10:00:00', $wrongBrand)->categories()->attach($women);
        $this->product('filter-wrong-category', '2026-10-22 10:00:00', $firstBrand)->categories()->attach($outside);
        $this->product('filter-no-category', '2026-10-22 10:00:00', $secondBrand);
        Category::fixTree();
        [$block, $translation] = $this->widget('latest', 3, settings: [
            'category_ids' => [$women->id, $men->id], 'manufacturer_ids' => [$firstBrand->id, $secondBrand->id], 'include_children' => true,
        ]);

        $ids = app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect())->pluck('id')->all();

        $this->assertSame([$menWatch->id, $childWatch->id, $womenWatch->id], $ids);
        $this->assertCount(3, array_unique($ids));
    }

    public function test_descendant_and_stock_switches_change_eligibility_without_including_inactive_products(): void
    {
        $root = $this->category('stock-root');
        $child = $this->category('stock-child', $root);
        $stocked = $this->product('stocked-parent', '2026-10-10 10:00:00');
        $stocked->categories()->attach($root);
        $soldOut = $this->product('sold-out-parent', '2026-10-11 10:00:00', attributes: ['stock_qty' => 0]);
        $soldOut->categories()->attach($root);
        $descendant = $this->product('stocked-child', '2026-10-12 10:00:00');
        $descendant->categories()->attach($child);
        $this->product('stock-inactive', '2026-10-22 10:00:00', attributes: ['is_active' => false])->categories()->attach($root);
        Category::fixTree();
        $service = app(WatchlineWidgetProducts::class);
        [$block, $translation] = $this->widget('latest', 10, settings: ['category_ids' => [$root->id], 'include_children' => false, 'in_stock_only' => true]);
        $this->assertSame([$stocked->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('latest', 10, settings: ['category_ids' => [$root->id], 'include_children' => false, 'in_stock_only' => false]);
        $this->assertSame([$soldOut->id, $stocked->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('latest', 10, settings: ['category_ids' => [$root->id], 'include_children' => true, 'in_stock_only' => false]);
        $this->assertSame([$descendant->id, $soldOut->id, $stocked->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
    }

    public function test_promotions_rank_actual_resolved_percentage_for_product_category_and_brand_targets(): void
    {
        $category = $this->category('promotion-category');
        $brand = $this->manufacturer('promotion-brand');
        $categoryOffer = $this->product('category-offer', '2026-10-01 10:00:00');
        $categoryOffer->categories()->attach($category);
        $brandOffer = $this->product('brand-offer', '2026-10-20 10:00:00', $brand);
        $fixedEuroOffer = $this->product('fixed-euro-offer', '2026-10-21 10:00:00', attributes: ['base_price' => 1000]);
        $higherFraction = $this->product('higher-fraction-offer', '2026-10-02 10:00:00');
        $lowerFraction = $this->product('lower-fraction-offer', '2026-10-22 10:00:00');
        $this->action(CatalogAction::TARGET_CATEGORY, $category->id, CatalogAction::TYPE_PERCENTAGE, 40);
        $this->action(CatalogAction::TARGET_MANUFACTURER, $brand->id, CatalogAction::TYPE_PERCENTAGE, 30);
        $this->action(CatalogAction::TARGET_PRODUCT, $fixedEuroOffer->id, CatalogAction::TYPE_FIXED, 100);
        $this->action(CatalogAction::TARGET_PRODUCT, $higherFraction->id, CatalogAction::TYPE_PERCENTAGE, 33.4);
        $this->action(CatalogAction::TARGET_PRODUCT, $lowerFraction->id, CatalogAction::TYPE_PERCENTAGE, 33.1);
        [$block, $translation] = $this->widget('promotions', 5);

        $products = app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect());

        $this->assertSame([$categoryOffer->id, $higherFraction->id, $lowerFraction->id, $brandOffer->id, $fixedEuroOffer->id], $products->pluck('id')->all());
        $prices = app(ProductPricePresentationService::class);
        $this->assertEqualsWithDelta(60, $prices->forProduct($categoryOffer)['display_current'], 0.001);
        $this->assertEqualsWithDelta(70, $prices->forProduct($brandOffer)['display_current'], 0.001);
        $this->assertEqualsWithDelta(900, $prices->forProduct($fixedEuroOffer)['display_current'], 0.001);
        [$block, $translation] = $this->widget('promotions', 2);
        $this->assertSame([$categoryOffer->id, $higherFraction->id], app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect())->pluck('id')->all());
    }

    public function test_expired_future_coupon_only_or_unusable_offers_do_not_fill_the_widget_limit(): void
    {
        $eligible = $this->product('eligible-public-offer', '2026-10-01 10:00:00');
        $this->action(CatalogAction::TARGET_PRODUCT, $eligible->id, CatalogAction::TYPE_PERCENTAGE, 10);
        foreach ([
            'expired-offer' => ['ends_at' => now()->subMinute()],
            'future-offer' => ['starts_at' => now()->addMinute()],
            'coupon-only-offer' => ['coupon_code' => 'TEST-PRIVATE-COUPON'],
            'inactive-offer' => ['is_active' => false],
        ] as $code => $attributes) {
            $product = $this->product($code, '2026-10-22 10:00:00');
            $this->action(CatalogAction::TARGET_PRODUCT, $product->id, CatalogAction::TYPE_PERCENTAGE, 90, $attributes);
        }
        $noImage = $this->product('offer-no-image', '2026-10-22 10:00:00', image: false);
        $this->action(CatalogAction::TARGET_PRODUCT, $noImage->id, CatalogAction::TYPE_PERCENTAGE, 95);
        $soldOut = $this->product('offer-no-stock', '2026-10-22 10:00:00', attributes: ['stock_qty' => 0]);
        $this->action(CatalogAction::TARGET_PRODUCT, $soldOut->id, CatalogAction::TYPE_PERCENTAGE, 95);
        $inactiveProduct = $this->product('offer-inactive-product', '2026-10-22 10:00:00', attributes: ['is_active' => false]);
        $this->action(CatalogAction::TARGET_PRODUCT, $inactiveProduct->id, CatalogAction::TYPE_PERCENTAGE, 95);
        [$block, $translation] = $this->widget('promotions', 1);

        $this->assertSame([$eligible->id], app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect())->pluck('id')->all());
    }

    public function test_new_products_require_the_explicit_catalog_flag_while_latest_keeps_date_order(): void
    {
        $flaggedOlder = $this->product('flagged-older', '2026-09-01 10:00:00');
        $flaggedOlder->update(['payload' => array_replace_recursive($flaggedOlder->payload, ['storefront' => ['is_new' => true]])]);
        $recentUnflagged = $this->product('recent-unflagged', '2026-10-22 10:00:00');
        $explicitFalse = $this->product('explicitly-not-new', '2026-10-21 10:00:00');
        $explicitFalse->update(['payload' => array_replace_recursive($explicitFalse->payload, ['storefront' => ['is_new' => false]])]);
        [$block, $translation] = $this->widget('new_products', 5);
        $service = app(WatchlineWidgetProducts::class);

        $this->assertSame([$flaggedOlder->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('latest', 2);
        $this->assertSame([$recentUnflagged->id, $explicitFalse->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
    }

    public function test_manual_order_remains_editor_controlled_and_filters_run_before_the_limit(): void
    {
        $category = $this->category('manual-category');
        $brand = $this->manufacturer('manual-brand');
        $wrongBrand = $this->manufacturer('manual-other-brand');
        $older = $this->product('manual-older', '2026-10-01 10:00:00', $brand);
        $newer = $this->product('manual-newer', '2026-10-22 10:00:00', $brand);
        $excluded = $this->product('manual-excluded-brand', '2026-10-23 10:00:00', $wrongBrand);
        foreach ([$older, $newer, $excluded] as $product) {
            $product->categories()->attach($category);
        }
        [$block, $translation] = $this->widget('manual', 2);
        $service = app(WatchlineWidgetProducts::class);
        $this->assertSame([$older->id, $newer->id], $service->forBlock($block, $translation, collect([$older, $newer]))->pluck('id')->all());
        [$block, $translation] = $this->widget('manual', 1, settings: ['category_ids' => [$category->id], 'manufacturer_ids' => [$brand->id]]);
        $this->assertSame([$older->id], $service->forBlock($block, $translation, collect([$excluded, $older, $newer]))->pluck('id')->all());
    }

    public function test_empty_legacy_manual_selection_uses_category_or_brand_filters_automatically_before_limiting(): void
    {
        $category = $this->category('legacy-automatic-category');
        $outside = $this->category('legacy-automatic-outside');
        $brand = $this->manufacturer('legacy-automatic-brand');
        $otherBrand = $this->manufacturer('legacy-automatic-other-brand');
        $older = $this->product('legacy-automatic-older', '2026-10-01 10:00:00', $brand);
        $middle = $this->product('legacy-automatic-middle', '2026-10-10 10:00:00', $brand);
        $newer = $this->product('legacy-automatic-newer', '2026-10-20 10:00:00', $brand);
        foreach ([$older, $middle, $newer] as $product) {
            $product->categories()->attach($category);
        }
        $categoryOnly = $this->product('legacy-category-only', '2026-10-22 10:00:00', $otherBrand);
        $categoryOnly->categories()->attach($category);
        $brandOnly = $this->product('legacy-brand-only', '2026-10-21 10:00:00', $brand);
        $brandOnly->categories()->attach($outside);
        $this->product('legacy-sold-out-match', '2026-10-23 10:00:00', $brand, attributes: ['stock_qty' => 0])->categories()->attach($category);
        $service = app(WatchlineWidgetProducts::class);
        [$block, $translation] = $this->widget('manual', 2, settings: ['category_ids' => [$category->id], 'manufacturer_ids' => [$brand->id]]);
        $this->assertSame([$newer->id, $middle->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('manual', 2, settings: ['category_ids' => [$category->id]]);
        $this->assertSame([$categoryOnly->id, $newer->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('manual', 2, settings: ['manufacturer_ids' => [$brand->id]]);
        $this->assertSame([$brandOnly->id, $newer->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('manual', 2, settings: ['category_ids' => [$category->id], 'manufacturer_ids' => [$brand->id]]);
        $this->assertSame([$older->id], $service->forBlock($block, $translation, collect([$older]))->pluck('id')->all());
    }

    public function test_filtered_source_uses_the_matching_catalog_instead_of_the_manual_selection(): void
    {
        $category = $this->category('filtered-source-category');
        $brand = $this->manufacturer('filtered-source-brand');
        $older = $this->product('filtered-source-older', '2026-10-01 10:00:00', $brand);
        $middle = $this->product('filtered-source-middle', '2026-10-10 10:00:00', $brand);
        $newer = $this->product('filtered-source-newer', '2026-10-20 10:00:00', $brand);
        foreach ([$older, $middle, $newer] as $product) {
            $product->categories()->attach($category);
        }
        $manualOutsideFilters = $this->product('filtered-source-unrelated-manual', '2026-10-22 10:00:00');
        $this->product('filtered-source-no-stock', '2026-10-23 10:00:00', $brand, attributes: ['stock_qty' => 0])->categories()->attach($category);
        [$block, $translation] = $this->widget('filtered', 2, settings: ['category_ids' => [$category->id], 'manufacturer_ids' => [$brand->id]]);

        $this->assertSame([$newer->id, $middle->id], app(WatchlineWidgetProducts::class)->forBlock($block, $translation, collect([$manualOutsideFilters]))->pluck('id')->all());
    }

    public function test_unavailable_saved_manual_items_do_not_expand_to_automatic_catalog_selection(): void
    {
        $category = $this->category('saved-manual-category');
        $activeUnselected = $this->product('saved-manual-active-unselected', '2026-10-20 10:00:00');
        $activeUnselected->categories()->attach($category);
        $inactiveSelected = $this->product('saved-manual-inactive-selected', '2026-10-21 10:00:00', attributes: ['is_active' => false]);
        $inactiveSelected->categories()->attach($category);
        [$block, $translation] = $this->widget('manual', 5, settings: ['category_ids' => [$category->id]]);
        $block->fill(['code' => 'saved-manual-filtered-widget', 'name' => 'Saved manual selection', 'is_active' => true])->save();
        $block->items()->create(['item_type' => 'product', 'item_id' => $inactiveSelected->id, 'sort_order' => 0]);
        $service = app(WatchlineWidgetProducts::class);

        // The renderer can omit an unavailable selected item before calling the selector.
        $this->assertCount(0, $service->forBlock($block, $translation, collect()));
        $block->items()->delete();
        $this->assertSame([$activeUnselected->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
    }

    public function test_popular_filter_uses_an_independent_manual_mark_across_automatic_and_manual_sources(): void
    {
        $newAndPopular = $this->product('new-and-popular', '2026-09-01 10:00:00');
        $popularOnly = $this->product('popular-without-new', '2026-10-10 10:00:00');
        $newOnly = $this->product('new-without-popular', '2026-10-21 10:00:00');
        $this->product('recent-unmarked-popularity', '2026-10-22 10:00:00');
        foreach ([
            [$newAndPopular, ['is_new' => true, 'is_popular' => true]],
            [$popularOnly, ['is_new' => false, 'is_popular' => true]],
            [$newOnly, ['is_new' => true, 'is_popular' => false]],
        ] as [$product, $flags]) {
            $product->update(['payload' => array_replace_recursive($product->payload, ['storefront' => $flags])]);
        }
        $this->discount($newAndPopular);
        $this->discount($newOnly);
        $service = app(WatchlineWidgetProducts::class);
        [$block, $translation] = $this->widget('latest', 10, settings: ['popular_only' => true]);
        $this->assertSame([$popularOnly->id, $newAndPopular->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('new_products', 10, settings: ['popular_only' => true]);
        $this->assertSame([$newAndPopular->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('promotions', 10, settings: ['popular_only' => true]);
        $this->assertSame([$newAndPopular->id], $service->forBlock($block, $translation, collect())->pluck('id')->all());
        [$block, $translation] = $this->widget('manual', 1, settings: ['popular_only' => true]);
        $this->assertSame([$popularOnly->id], $service->forBlock($block, $translation, collect([$newOnly, $popularOnly, $newAndPopular]))->pluck('id')->all());
    }

    public function test_empty_automatic_selection_never_falls_back_to_manual_products_or_unfiltered_catalog(): void
    {
        $fullPrice = $this->product('automatic-no-fallback', '2026-10-22 10:00:00');
        $emptyCategory = $this->category('empty-widget-category');
        $service = app(WatchlineWidgetProducts::class);
        [$block, $translation] = $this->widget('latest', 5, settings: ['category_ids' => [$emptyCategory->id]]);
        $this->assertCount(0, $service->forBlock($block, $translation, collect([$fullPrice])));
        [$block, $translation] = $this->widget('promotions', 5);
        $this->assertCount(0, $service->forBlock($block, $translation, collect([$fullPrice])));
        [$block, $translation] = $this->widget('new_products', 5);
        $this->assertCount(0, $service->forBlock($block, $translation, collect([$fullPrice])));
        [$block, $translation] = $this->widget('manual', 5);
        $this->assertCount(0, $service->forBlock($block, $translation, collect()));
    }

    private function widget(string $source, int $limit, bool $promotionsOnly = false, array $settings = []): array
    {
        return [
            new ContentBlock(['type' => 'watchline_products']),
            new ContentBlockTranslation(['payload' => [
                'items_limit' => $limit,
                'watchline' => array_replace(['source' => $source, 'promotions_only' => $promotionsOnly], $settings),
            ]]),
        ];
    }

    private function product(string $code, string $dateAdded, ?Manufacturer $manufacturer = null, bool $image = true, array $attributes = []): Product
    {
        $product = Product::create(array_replace([
            'code' => $code, 'sku' => $code, 'manufacturer_id' => $manufacturer?->id,
            'is_active' => true, 'stock_qty' => 3, 'base_price' => 100,
            'payload' => ['opencart' => ['image' => $image ? 'catalog/widget-'.$code.'.jpg' : '', 'date_added' => $dateAdded]],
        ], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $product;
    }

    private function discount(Product $product): void
    {
        $action = CatalogAction::create([
            'code' => 'widget-discount-'.$product->id,
            'scope' => CatalogAction::SCOPE_PRODUCT, 'type' => 'fixed_amount',
            'discount_value' => 10, 'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL, 'is_active' => true,
        ]);
        $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);
    }

    private function category(string $code, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => $code, 'scope' => 'catalog', 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => $code, 'slug' => $code]);

        return $category;
    }

    private function manufacturer(string $code): Manufacturer
    {
        $manufacturer = Manufacturer::create(['code' => $code, 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $manufacturer;
    }

    private function action(string $targetType, int $targetId, string $type, float $value, array $attributes = []): CatalogAction
    {
        $action = CatalogAction::create(array_replace([
            'code' => 'widget-automatic-action-'.CatalogAction::count(), 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => $type, 'discount_value' => $value, 'target_type' => $targetType,
            'audience_type' => CatalogAction::AUDIENCE_ALL, 'is_active' => true,
        ], $attributes));
        $action->targets()->create(['target_type' => $targetType, 'target_id' => $targetId]);

        return $action;
    }
}
