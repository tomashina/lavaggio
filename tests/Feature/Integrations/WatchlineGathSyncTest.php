<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Stock\StockSyncRun;
use App\Services\Integrations\Watchline\GathSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class WatchlineGathSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'commerce.b2c_only' => true]);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_preview_reads_the_original_headerless_bom_format_without_mutating_products_or_audits(): void
    {
        $payload = ['opencart' => ['product_id' => 77, 'sku' => '00123', 'model' => 'OTHER'], 'storefront' => ['is_new' => true], 'watchline_anchor_price' => ['gross_price' => '50']];
        $product = $this->product('adjusted', ['sku' => '00123-oc-77', 'stock_qty' => 2, 'base_price' => 50, 'payload' => $payload]);
        $file = $this->csv("\xEF\xBB\xBF00123;7;12,3456;IGNORED BRAND\nNO-MATCH;1;99,00;CLUSE\n");
        $before = $product->fresh()->getAttributes();

        $report = app(GathSyncService::class)->preview($file);

        $this->assertTrue($report['can_apply']);
        $this->assertSame(2, $report['fetched_count']);
        $this->assertSame(1, $report['matched_count']);
        $this->assertSame(1, $report['unmatched_count']);
        $this->assertSame('00123', $report['examples'][0]['sku']);
        $this->assertSame('12.3456', $report['examples'][0]['new_price']);
        $this->assertSame(hash_file('sha256', $file), $report['checksum']);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('stock_sync_runs', 0);
        $this->assertDatabaseCount('stock_sync_items', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_non_bom_first_sku_and_duplicate_original_skus_follow_the_legacy_sku_mapping(): void
    {
        $first = $this->product('first', ['sku' => '00123-adjusted', 'payload' => ['opencart' => ['product_id' => 1, 'sku' => '00123']]]);
        $duplicate = $this->product('second', ['sku' => '00123-other', 'payload' => ['opencart' => ['product_id' => 2, 'sku' => '00123']]]);
        $blank = $this->product('blank-source', ['sku' => '00123', 'stock_qty' => 9, 'payload' => ['opencart' => ['product_id' => 3, 'sku' => '']]]);

        $report = app(GathSyncService::class)->apply($this->csv("00123;3;225,00;CLUSE\n00123;3;225.00;CLUSE\n"));

        $this->assertSame('completed', $report['status']);
        $this->assertSame(2, $report['matched_count']);
        $this->assertSame(1, $report['matched_identifiers']);
        $this->assertSame(1, $report['duplicate_product_sku_count']);
        $this->assertSame(1, $report['duplicate_count']);
        $this->assertSame(3, $first->fresh()->stock_qty);
        $this->assertSame(3, $duplicate->fresh()->stock_qty);
        $this->assertSame(9, $blank->fresh()->stock_qty);
        $this->assertSame(225.0, (float) $first->fresh()->getRawOriginal('base_price'));
    }

    public function test_leading_source_sku_spaces_remain_significant_even_when_native_sku_was_trimmed_during_import(): void
    {
        $inactive = $this->product('source-space', ['sku' => 'SOURCE', 'is_active' => false, 'stock_qty' => 0, 'payload' => ['opencart' => ['product_id' => 1, 'sku' => ' SOURCE']]]);
        $normal = $this->product('normal-space', ['sku' => 'NORMAL', 'payload' => ['opencart' => ['product_id' => 2, 'sku' => 'NORMAL ']]]);
        $file = $this->csv("SOURCE;3;10,00\nnormal;2;20,00\n");

        $report = app(GathSyncService::class)->apply($file);

        $this->assertSame(1, $report['matched_count']);
        $this->assertSame(1, $report['unmatched_count']);
        $this->assertSame(0, $report['reactivated_count']);
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertSame(0, $inactive->fresh()->stock_qty);
        $this->assertSame(2, $normal->fresh()->stock_qty);

        $exact = app(GathSyncService::class)->apply($this->csv(" SOURCE;3;10,00\n"));
        $this->assertSame(1, $exact['matched_count']);
        $this->assertTrue($inactive->fresh()->is_active);
    }

    public function test_apply_preserves_promotions_published_catalogs_source_payload_and_supplier_stock_and_is_idempotent(): void
    {
        $payload = ['opencart' => ['product_id' => 77, 'sku' => 'WATCH', 'price' => '50.0000'], 'storefront' => ['is_popular' => true], 'watchline_anchor_price' => ['gross_price' => '60']];
        $product = $this->product('watch', ['payload' => $payload, 'is_active' => false, 'base_price' => 50, 'stock_qty' => 1, 'supplier_stock_qty' => 14]);
        $draft = PriceCatalog::query()->create(['name' => 'Watchline snapshot', 'source_system' => 'herrera-opencart', 'source_snapshot' => 'watchline_snapshot_test']);
        $base = $this->entry($draft, $product, 'base', 'product:77', '50');
        $special = $this->entry($draft, $product, 'special', 'special:77', '20');
        $quantity = $this->entry($draft, $product, 'quantity', 'quantity:77', '19');
        $manual = $this->entry($draft, $product, 'base', 'manual:77', '45');
        $published = PriceCatalog::query()->create(['name' => 'Published', 'source_system' => 'watchline-opencart']);
        $publishedBase = $this->entry($published, $product, 'base', 'product:77', '48');
        $published->update(['status' => PriceCatalog::ACTIVE]);
        $historyBefore = $product->priceHistory()->count();
        $file = $this->csv("WATCH;5;12,3456;WRONG MANUFACTURER\n");

        $report = app(GathSyncService::class)->apply($file);

        $this->assertSame(1, $report['updated_count']);
        $this->assertSame(1, $report['reactivated_count']);
        $this->assertSame(1, $report['draft_base_entry_updated_count']);
        $this->assertSame(12.3456, (float) $product->fresh()->getRawOriginal('base_price'));
        $this->assertSame(5, $product->fresh()->stock_qty);
        $this->assertSame(14, $product->fresh()->supplier_stock_qty);
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame($payload, $product->fresh()->payload);
        $this->assertSame('12.3456', $base->fresh()->price);
        foreach ([[$special, '20.0000'], [$quantity, '19.0000'], [$manual, '45.0000'], [$publishedBase, '48.0000']] as [$entry, $expected]) {
            $this->assertSame($expected, $entry->fresh()->price);
        }
        $this->assertSame($historyBefore + 1, $product->priceHistory()->count());
        $this->assertDatabaseHas('catalog_product_price_history', ['product_id' => $product->id, 'source' => 'gath', 'old_price' => 50, 'new_price' => 12.3456]);
        $firstUpdatedAt = $product->fresh()->getRawOriginal('updated_at');

        $repeated = app(GathSyncService::class)->apply($file);

        $this->assertSame(0, $repeated['updated_count']);
        $this->assertSame(1, $repeated['unchanged_count']);
        $this->assertSame(0, $repeated['draft_base_entry_updated_count']);
        $this->assertSame($historyBefore + 1, $product->priceHistory()->count());
        $this->assertSame($firstUpdatedAt, $product->fresh()->getRawOriginal('updated_at'));
        $this->assertDatabaseCount('stock_sync_runs', 2);
        $this->assertDatabaseHas('stock_sync_items', ['run_id' => $report['run_id'], 'product_id' => $product->id, 'old_quantity' => 1, 'new_quantity' => 5, 'status' => 'updated']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_reset_missing_is_opt_in_and_only_zeros_managed_own_stock(): void
    {
        $matched = $this->product('found', ['sku' => 'FOUND', 'stock_qty' => 2, 'payload' => ['opencart' => ['product_id' => 1, 'sku' => 'FOUND']]]);
        $missing = $this->product('absent', ['stock_qty' => 8, 'supplier_stock_qty' => 13, 'payload' => ['opencart' => ['product_id' => 2, 'sku' => 'ABSENT']]]);
        $native = $this->product('native', ['stock_qty' => 15]);
        $file = $this->csv("FOUND;2;50,00;CLUSE\n");
        $sync = app(GathSyncService::class);

        $preview = $sync->preview($file);
        $default = $sync->apply($file);

        $this->assertSame(1, $preview['reset_candidate_count']);
        $this->assertSame(0, $default['reset_count']);
        $this->assertSame(8, $missing->fresh()->stock_qty);
        $reset = $sync->apply($file, true);
        $this->assertSame(1, $reset['reset_count']);
        $this->assertSame(0, $missing->fresh()->stock_qty);
        $this->assertSame(13, $missing->fresh()->supplier_stock_qty);
        $this->assertSame(15, $native->fresh()->stock_qty);
        $this->assertSame(2, $matched->fresh()->stock_qty);
    }

    public function test_a_native_explicit_sku_match_becomes_managed_without_losing_its_payload(): void
    {
        $native = $this->product('native-match', ['sku' => 'NATIVE-SKU', 'payload' => ['storefront' => ['is_new' => true]]]);
        $sync = app(GathSyncService::class);
        $sync->apply($this->csv("NATIVE-SKU;5;10,00\n"));
        $this->assertTrue($native->fresh()->payload['gath']['managed']);
        $this->assertTrue($native->fresh()->payload['storefront']['is_new']);
        $this->product('other-feed', ['sku' => 'OTHER-SKU']);

        $report = $sync->apply($this->csv("OTHER-SKU;1;20,00\n"), true);

        $this->assertSame(1, $report['reset_count']);
        $this->assertSame(0, $native->fresh()->stock_qty);
    }

    public function test_empty_malformed_and_conflicting_files_never_partially_apply_or_zero_stock(): void
    {
        $product = $this->product('protected', ['sku' => 'SKU', 'stock_qty' => 8, 'base_price' => 50, 'payload' => ['opencart' => ['product_id' => 1, 'sku' => 'SKU']]]);
        foreach (["\n", "SKU;2;12,00\nBROKEN;-1;9\n", "SKU;1.5;12,00\n", "SKU;1;12,12345\n", "SKU;1;12,00\nSKU;2;12,00\n"] as $content) {
            try {
                app(GathSyncService::class)->apply($this->csv($content), true);
                $this->fail('Invalid feed must be rejected atomically.');
            } catch (RuntimeException) {
                $this->assertSame(8, $product->fresh()->stock_qty);
                $this->assertSame(50.0, (float) $product->fresh()->getRawOriginal('base_price'));
            }
        }
        $this->assertDatabaseCount('stock_sync_runs', 0);
        Http::assertNothingSent();
    }

    public function test_unrelated_valid_file_cannot_zero_the_catalog_and_records_a_safe_failed_run(): void
    {
        $product = $this->product('protected-no-match', ['stock_qty' => 8, 'payload' => ['opencart' => ['product_id' => 1, 'sku' => 'KNOWN']]]);
        $file = $this->csv("UNKNOWN;3;10,00;BRAND\n");
        $this->assertFalse(app(GathSyncService::class)->preview($file)['can_apply']);
        try {
            app(GathSyncService::class)->apply($file, true);
            $this->fail('Unrelated feed must not clear inventory.');
        } catch (RuntimeException) {
            $this->assertSame(8, $product->fresh()->stock_qty);
            $this->assertSame('failed', StockSyncRun::query()->sole()->status);
            $this->assertDatabaseCount('stock_sync_items', 0);
            $this->assertSame(0, StockSyncRun::query()->sole()->updated_count);
        }
    }

    public function test_shared_price_or_stock_lock_rejects_overlapping_imports(): void
    {
        $this->product('locked', ['sku' => 'LOCKED']);
        $file = $this->csv("LOCKED;1;12,00\n");
        $lock = Cache::lock('herrera-stock-sync:stock_qty', 600);
        $lock->get();
        try {
            app(GathSyncService::class)->apply($file);
            $this->fail('Concurrent import must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('stock_sync_runs', 0);
            $priceLock = Cache::lock('herrera-stock-sync:base_price', 600);
            $this->assertTrue($priceLock->get());
            $priceLock->release();
        } finally {
            $lock->release();
        }
    }

    public function test_command_previews_by_default_and_requires_explicit_apply(): void
    {
        $product = $this->product('command', ['sku' => 'CLI', 'stock_qty' => 8, 'base_price' => 50]);
        $file = $this->csv("CLI;3;22,00\n");
        $this->artisan('watchline:gath-sync', ['--file' => $file])->assertSuccessful();
        $this->assertSame(8, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_sync_runs', 0);

        $this->artisan('watchline:gath-sync', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame(22.0, (float) $product->fresh()->getRawOriginal('base_price'));
        $this->assertDatabaseCount('stock_sync_runs', 1);
    }

    private function product(string $code, array $attributes = []): Product
    {
        return Product::query()->create($attributes + ['code' => $code, 'sku' => $code, 'base_price' => 50, 'stock_qty' => 0, 'supplier_stock_qty' => 0, 'is_active' => true]);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $key, string $price): PriceCatalogEntry
    {
        return PriceCatalogEntry::query()->create(['price_catalog_id' => $catalog->id, 'product_id' => $product->id, 'kind' => $kind, 'source_key' => $key, 'price' => $price]);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'watchline-gath-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
