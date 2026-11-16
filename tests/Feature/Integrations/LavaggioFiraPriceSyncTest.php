<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Fira\Dashboard;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Integrations\Fira\FiraPriceSyncService;
use App\Services\Integrations\Fira\FiraSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioFiraPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'storefront_cache.enabled' => false]);
        Http::preventStrayRequests();
        TaxRate::create(['code' => 'vat25', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true, 'is_default' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $settings = app(FiraSettingsService::class);
        $settings->save($settings->options(), 'fixture-fira-key');
    }

    public function test_all_pages_match_by_sku_and_preserve_stock_listing_prices_and_content(): void
    {
        $product = $this->product('DETERGENT');
        $second = $this->product('SOFTENER');
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::sequence()
            ->push($this->page([$this->row(1, 'DETERGENT', 15)], 0, 2, 2))
            ->push($this->page([$this->row(2, 'SOFTENER', 18)], 1, 2, 2))]);

        $result = app(FiraPriceSyncService::class)->sync(true);

        $this->assertSame(2, $result['changed']);
        $this->assertFalse($result['stock_synced']);
        $this->assertSame('15.00', $product->fresh()->base_price);
        $this->assertSame('18.00', $second->fresh()->base_price);
        $this->assertSame(7, $product->fresh()->stock_qty);
        $this->assertSame('10.00', $product->fresh()->listing_price);
        $this->assertSame('2026-11-16', $product->fresh()->listed_at->toDateString());
        $this->assertSame(['keep' => 'editorial'], $product->fresh()->payload);
        $this->assertDatabaseHas('catalog_product_price_history', ['product_id' => $product->id, 'old_price' => 10, 'new_price' => 15]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('FIRA-Api-Key', 'fixture-fira-key') && $request['page'] === 1);
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
    }

    public function test_preview_and_identical_reruns_do_not_write_price_history(): void
    {
        $product = $this->product('SKU');
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response($this->page([$this->row(1, 'SKU', 12.5)]))]);
        $this->assertSame(1, app(FiraPriceSyncService::class)->sync()['changed']);
        $this->assertSame('10.00', $product->fresh()->base_price);
        $this->assertNull(app(SystemSettingsService::class)->get('lavaggio_fira_price_sync'));
        app(FiraPriceSyncService::class)->sync(true);
        $historyCount = $product->priceHistory()->count();
        $this->assertSame(0, app(FiraPriceSyncService::class)->sync(true)['changed']);
        $this->assertSame($historyCount, $product->priceHistory()->count());
    }

    public function test_failed_later_page_never_partially_changes_prices(): void
    {
        $product = $this->product('SKU');
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::sequence()
            ->push($this->page([$this->row(1, 'SKU', 20)], 0, 2, 2))->push([], 503)]);
        $this->assertRejected();
        $this->assertSame('10.00', $product->fresh()->base_price);
        $this->assertNull(app(SystemSettingsService::class)->get('lavaggio_fira_price_sync'));
    }

    public function test_duplicate_skus_bad_tax_currency_and_missing_prices_are_skipped(): void
    {
        foreach (['DUP', 'TAX', 'USD', 'NULL', 'UNKNOWN'] as $sku) {
            $this->product($sku);
        }
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response($this->page([
            $this->row(1, 'DUP', 20), $this->row(2, 'DUP', 30),
            [...$this->row(3, 'TAX', 20), 'taxPercentage' => 13],
            [...$this->row(4, 'USD', 20), 'currency' => 'USD'],
            [...$this->row(5, 'NULL', 20), 'grossPrice' => null],
            $this->row(6, 'NO-MATCH', 20),
        ]))]);
        $result = app(FiraPriceSyncService::class)->sync(true);
        $this->assertSame(0, $result['changed']);
        $this->assertSame(2, $result['ambiguous']);
        $this->assertSame(3, $result['invalid']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertSame(0, Product::where('base_price', '!=', 10)->count());
    }

    public function test_live_tax_amount_field_is_accepted_only_with_consistent_net_and_gross_prices(): void
    {
        $valid = $this->product('LIVE');
        $rounded = $this->product('ROUNDED');
        $wrongRate = $this->product('WRONG-RATE');
        $wrongAmount = $this->product('WRONG-AMOUNT');
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response($this->page([
            [...$this->row(1, 'LIVE', 5.99), 'netPrice' => 4.792, 'taxPercentage' => 1.198],
            [...$this->row(2, 'ROUNDED', 5.99), 'netPrice' => 4.79],
            [...$this->row(3, 'WRONG-RATE', 11.3), 'netPrice' => 10, 'taxPercentage' => 1.3],
            [...$this->row(4, 'WRONG-AMOUNT', 12.5), 'netPrice' => 10, 'taxPercentage' => 1.3],
        ]))]);

        $result = app(FiraPriceSyncService::class)->sync(true);
        $this->assertSame(2, $result['changed']);
        $this->assertSame(2, $result['invalid']);
        $this->assertSame('5.99', $valid->fresh()->base_price);
        $this->assertSame('5.99', $rounded->fresh()->base_price);
        $this->assertSame('10.00', $wrongRate->fresh()->base_price);
        $this->assertSame('10.00', $wrongAmount->fresh()->base_price);
    }

    public function test_variant_prices_use_their_own_sku_and_respect_net_storage(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $product = $this->product('PARENT');
        $option = Option::create(['code' => 'pack', 'type' => 'select', 'is_active' => true]);
        $value = OptionValue::create(['option_id' => $option->id, 'code' => '5l', 'is_active' => true]);
        $variant = $product->optionValues()->create(['option_value_id' => $value->id, 'mode' => 'single',
            'sku' => 'VARIANT-5L', 'stock_qty' => 4, 'is_active' => true, 'combination_hash' => hash('sha256', 's:'.$value->id)]);
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response($this->page([$this->row(1, 'VARIANT-5L', 25)]))]);
        app(FiraPriceSyncService::class)->sync(true);
        $this->assertSame('20.00', $variant->fresh()->price_override);
        $this->assertSame(4, $variant->fresh()->stock_qty);
        $this->assertSame('10.00', $product->fresh()->base_price);
    }

    public function test_duplicate_local_sku_is_not_arbitrarily_assigned(): void
    {
        $this->product('SKU')->update(['code' => 'OTHER']);
        Product::create(['code' => 'SKU', 'sku' => null, 'base_price' => 10]);
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response($this->page([$this->row(1, 'SKU', 25)]))]);
        $result = app(FiraPriceSyncService::class)->sync(true);
        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(0, $result['changed']);
    }

    public function test_invalid_pagination_and_duplicate_ids_fail_before_writing(): void
    {
        $product = $this->product('SKU');
        foreach ([
            ['items' => [], 'page' => 0, 'totalItems' => 1, 'totalPages' => 1],
            $this->page([$this->row(1, 'SKU', 20), $this->row(1, 'SKU', 20)]),
            ['items' => [], 'page' => 0, 'totalPages' => 101, 'totalItems' => 10001],
        ] as $payload) {
            Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response($payload)]);
            $this->assertRejected();
            $this->assertSame('10.00', $product->fresh()->base_price);
        }
    }

    public function test_permissions_and_legacy_sequence_settings(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(Dashboard::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_scheduled_command_does_not_run_until_enabled(): void
    {
        $this->app->instance('env', 'staging');
        $this->artisan('lavaggio:sync-fira-prices --apply --scheduled')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_admin_sync_reports_errors_and_uses_updated_sequences(): void
    {
        $user = User::factory()->create();
        $settings = app(FiraSettingsService::class);
        app(SystemSettingsService::class)->put('lavaggio_fira', [...$settings->options(), 'sequence' => '1-web-1']);
        $this->assertSame('WS-1', $settings->options()['sequence']);
        $this->assertSame('WS-2', $settings->options()['einvoice_sequence']);
        Bouncer::assign('superadmin')->to($user);
        Http::fake([FiraSettingsService::PRODUCTS_ENDPOINT.'*' => Http::response([], 403)]);
        Livewire::actingAs($user)->test(Dashboard::class)->call('syncPrices')->assertHasErrors('fira_sync');
    }

    private function assertRejected(): void
    {
        try {
            app(FiraPriceSyncService::class)->sync(true);
            $this->fail('Invalid reads must not be applied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fira_sync', $exception->errors());
        }
    }

    private function product(string $sku): Product
    {
        return Product::create(['code' => $sku, 'sku' => $sku, 'base_price' => 10, 'listing_price' => 10,
            'listed_at' => '2026-11-16', 'stock_qty' => 7, 'payload' => ['keep' => 'editorial'], 'is_active' => true]);
    }

    private function row(int $id, string $sku, float $gross): array
    {
        return ['id' => $id, 'productNumber' => $sku, 'status' => 'ACTIVE', 'currency' => 'EUR',
            'grossPrice' => $gross, 'netPrice' => $gross / 1.25, 'taxPercentage' => 25];
    }

    private function page(array $rows, int $page = 0, int $pages = 1, ?int $total = null): array
    {
        return ['items' => $rows, 'page' => $page, 'size' => 100, 'totalPages' => $pages, 'totalItems' => $total ?? count($rows)];
    }
}
