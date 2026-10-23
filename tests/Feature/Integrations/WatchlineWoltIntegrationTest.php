<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Wolt\Dashboard;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\System\SystemSetting;
use App\Models\User;
use App\Services\Integrations\Wolt\WoltLegacyConfigurationImporter;
use App\Services\Integrations\Wolt\WoltPayloadService;
use App\Services\Integrations\Wolt\WoltSettingsService;
use App\Services\Integrations\Wolt\WoltSyncService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineWoltIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'watchline',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
            'commerce.local_safe_mode' => true,
        ]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        Http::preventStrayRequests();
    }

    public function test_settings_encrypt_credentials_and_blank_fields_preserve_them_without_hydrating_admin_state(): void
    {
        $settings = app(WoltSettingsService::class);
        $settings->save($settings->options(), ['username' => 'fixture-wolt-user', 'password' => ' fixture-private-password ']);
        $stored = SystemSetting::query()->where('key', 'watchline_wolt_credentials')->value('value');
        $this->assertStringNotContainsString('fixture-wolt-user', $stored);
        $this->assertStringNotContainsString('fixture-private-password', $stored);
        $settings->save($settings->options(), ['username' => '', 'password' => '']);
        $this->assertSame(' fixture-private-password ', $settings->credentials()['password']);
        $this->assertTrue($settings->credentialsConfigured());

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->assertSet('credentials', ['username' => '', 'password' => '', 'access_token' => ''])
            ->assertSee('Pristupni podaci su spremljeni šifrirano.')
            ->assertDontSee('fixture-wolt-user')->assertDontSee('fixture-private-password');
        Http::assertNothingSent();
    }

    public function test_catalog_preview_uses_current_public_price_in_integer_cents_and_does_not_send_stock_boolean(): void
    {
        $product = $this->product('WOLT-PRICE', ['base_price' => 129.99]);
        $action = CatalogAction::create([
            'code' => 'wolt-fixture-discount', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 20,
            'is_active' => true, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'target_type' => CatalogAction::TARGET_PRODUCT,
        ]);
        $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);
        $this->configure([$product]);

        $result = app(WoltPayloadService::class)->preview('catalog');

        $this->assertSame([], $result['errors']);
        $this->assertSame(['sku' => 'WOLT-PRICE', 'price' => 10399, 'enabled' => true, 'vat_percentage' => 0.0], $result['payload']['data'][0]);
        $this->assertArrayNotHasKey('in_stock', $result['payload']['data'][0]);
        $this->assertSame(129.99, (float) $product->fresh()->base_price);
        Http::assertNothingSent();
    }

    public function test_inventory_preview_uses_only_physical_stock_and_explicitly_clears_inactive_or_negative_stock(): void
    {
        $available = $this->product('WOLT-STOCK', ['stock_qty' => 3, 'supplier_stock_qty' => 700]);
        $inactive = $this->product('WOLT-INACTIVE', ['is_active' => false, 'stock_qty' => 9]);
        $negative = $this->product('WOLT-NEGATIVE', ['stock_qty' => -2]);
        $this->product('WOLT-UNSELECTED', ['stock_qty' => 99]);
        $this->configure([$available, $inactive, $negative]);

        $result = app(WoltPayloadService::class)->preview('inventory');

        $this->assertSame([
            ['sku' => 'WOLT-STOCK', 'inventory' => 3],
            ['sku' => 'WOLT-INACTIVE', 'inventory' => 0],
            ['sku' => 'WOLT-NEGATIVE', 'inventory' => 0],
        ], $result['payload']['data']);
        $this->assertSame(-2, $negative->fresh()->stock_qty);
        Http::assertNothingSent();
    }

    public function test_duplicate_or_missing_wolt_identifiers_block_a_complete_export_instead_of_inventing_identifiers(): void
    {
        $first = $this->product('FIRST', ['payload' => ['integrations' => ['wolt' => ['sku' => 'SAME']]]]);
        $second = $this->product('SECOND', ['stock_qty' => 7, 'payload' => ['integrations' => ['wolt' => ['sku' => 'SAME']]]]);
        $missing = $this->product('MISSING');
        $this->configure([$first, $second, $missing], ['sku_field' => 'explicit']);

        $result = app(WoltPayloadService::class)->preview('inventory');

        $this->assertCount(1, $result['errors']);
        $this->assertSame(1, $result['skipped_conflicts']);
        $this->assertSame([], $result['payload']['data']);
        $this->assertSame(3, $result['selected_count']);
        Http::assertNothingSent();
    }

    public function test_identical_duplicate_legacy_rows_are_deduplicated_like_the_original_sql_distinct(): void
    {
        $first = $this->product('DISTINCT-FIRST', ['payload' => ['integrations' => ['wolt' => ['sku' => 'SAME-DISTINCT']]]]);
        $second = $this->product('DISTINCT-SECOND', ['payload' => ['integrations' => ['wolt' => ['sku' => 'SAME-DISTINCT']]]]);
        $this->configure([$first, $second], ['sku_field' => 'explicit']);

        $result = app(WoltPayloadService::class)->preview('inventory');

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['item_count']);
        $this->assertSame(1, $result['deduplicated_count']);
        $this->assertSame([['sku' => 'SAME-DISTINCT', 'inventory' => 5]], $result['payload']['data']);
        Http::assertNothingSent();
    }

    public function test_source_id_mapping_is_explicit_and_empty_selection_never_falls_back_to_the_catalog(): void
    {
        $product = $this->product('SOURCE-MODEL', ['payload' => ['source' => ['product_id' => 728]]]);
        $this->configure([$product], ['sku_field' => 'source_product_id']);
        $this->assertSame('728', app(WoltPayloadService::class)->preview('inventory')['payload']['data'][0]['sku']);
        $this->configure([]);
        $result = app(WoltPayloadService::class)->preview('inventory');
        $this->assertSame([], $result['payload']['data']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_local_safe_mode_is_a_send_blocker_even_when_every_connection_switch_is_enabled(): void
    {
        $settings = $this->configure([$this->product('READY')], [
            'catalog_venue_id' => 'fixture-venue', 'inventory_venue_id' => 'fixture-venue', 'mapping_confirmed' => true,
            'auth_confirmed' => true, 'send_enabled' => true,
        ]);
        $settings->save($settings->options(), ['username' => 'fixture-user', 'password' => 'fixture-password']);

        $this->assertSame(['Lokalni pregled: slanje u Wolt je isključeno.'], $settings->sendBlockers());
        $this->assertSame('https://pos-integration-service.development.dev.woltapi.com/venues/fixture-venue/items', $settings->endpoint('catalog'));
        $this->assertSame('https://pos-integration-service.development.dev.woltapi.com/venues/fixture-venue/items/inventory', $settings->endpoint('inventory'));
        Http::assertNothingSent();
    }

    public function test_endpoint_configuration_rejects_untrusted_hosts_and_path_injection(): void
    {
        $settings = app(WoltSettingsService::class);
        try {
            $settings->save(array_replace($settings->options(), ['catalog_venue_id' => '../other?secret=x']));
            $this->fail('Invalid venue identifiers must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('catalog_venue_id', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $settings->save(array_replace($settings->options(), ['environment' => 'https://attacker.invalid']));
    }

    public function test_unprivileged_users_cannot_open_or_run_the_wolt_admin_component(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Dashboard::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_existing_stock_integration_permission_opens_the_real_admin_route_without_a_new_role(): void
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to('admin.access');
        Bouncer::allow($user)->to('integrations.stock.manage');
        $this->actingAs($user)->get(route('admin.integrations.wolt.index'))
            ->assertOk()->assertSee('Pregled spremljenog izvoza')->assertSee('Spremi Wolt postavke');
        Http::assertNothingSent();
    }

    public function test_product_search_is_bounded_and_preview_uses_only_saved_selection(): void
    {
        $first = $this->product('FIND-WOLT-FIRST');
        $second = $this->product('FIND-WOLT-SECOND');
        foreach (range(1, 25) as $index) {
            $this->product('FIND-WOLT-'.$index);
        }
        $this->configure([$first]);
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->set('productSearch', 'FIND-WOLT')
            ->assertViewHas('searchProducts', fn ($products): bool => $products->count() === 20)
            ->call('addProduct', $second->id)
            ->call('previewPayload', 'inventory')
            ->assertSet('preview.selected_count', 1)
            ->call('save')->assertHasNoErrors()
            ->call('previewPayload', 'inventory')->assertSet('preview.selected_count', 2);
        Http::assertNothingSent();
    }

    public function test_legacy_source_scope_keeps_original_model_base_price_and_quantity_contract_for_inactive_products(): void
    {
        $inactive = $this->product('NATIVE-CHANGED-CODE', [
            'is_active' => false, 'stock_qty' => 8, 'base_price' => 123.45,
            'payload' => ['opencart' => ['product_id' => 17, 'model' => 'ORIGINAL-MODEL']],
        ]);
        $soldOut = $this->product('SOURCE-SOLD-OUT', [
            'stock_qty' => 0, 'payload' => ['opencart' => ['product_id' => 18, 'model' => 'ORIGINAL-ZERO']],
        ]);
        $this->product('NOT-IMPORTED');
        $this->configure([], [
            'scope' => 'source_imported', 'sku_field' => 'opencart_model',
            'price_basis' => 'stored_base', 'availability_basis' => 'stock_quantity', 'include_vat' => false,
        ]);
        $catalog = app(WoltPayloadService::class)->preview('catalog');
        $inventory = app(WoltPayloadService::class)->preview('inventory');
        $this->assertSame([
            ['sku' => 'ORIGINAL-MODEL', 'price' => 12345, 'enabled' => true],
            ['sku' => 'ORIGINAL-ZERO', 'price' => 10000, 'enabled' => false],
        ], $catalog['payload']['data']);
        $this->assertSame([
            ['sku' => 'ORIGINAL-MODEL', 'inventory' => 8], ['sku' => 'ORIGINAL-ZERO', 'inventory' => 0],
        ], $inventory['payload']['data']);
        $this->assertSame(2, $catalog['selected_count']);
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertSame(0, $soldOut->fresh()->stock_qty);
        Http::assertNothingSent();
    }

    public function test_preview_is_default_and_actual_sending_stays_blocked_in_safe_mode(): void
    {
        $this->readyConnection([$this->product('PREVIEW-ONLY')]);
        $sync = app(WoltSyncService::class);
        $this->assertSame('preview', $sync->run('wolt_inventory')['status']);
        $this->assertTrue($sync->preview('wolt_catalog')['blocked']);
        $blocked = $sync->run('wolt_inventory', false);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertFalse($blocked['dry_run']);
        Http::assertNothingSent();
    }

    public function test_mocked_live_requests_use_separate_venues_and_send_only_changed_rows_after_rate_limit(): void
    {
        config(['commerce.local_safe_mode' => false]);
        $product = $this->product('DELTA', ['stock_qty' => 2]);
        $this->readyConnection([$product]);
        Http::fake(['*' => Http::response('', 202)]);
        $sync = app(WoltSyncService::class);
        $this->assertSame('accepted', $sync->run('wolt_inventory', false)['status']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://pos-integration-service.development.dev.woltapi.com/venues/inventory-fixture/items/inventory'
            && $request->data() === ['data' => [['sku' => 'DELTA', 'inventory' => 2]]]);
        $this->assertSame('unchanged', $sync->run('wolt_inventory', false)['status']);
        $product->update(['stock_qty' => 4]);
        $this->assertSame('blocked', $sync->run('wolt_inventory', false)['status']);
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->assertSame(1, $sync->run('wolt_inventory', false)['counts']['sent']);
        $this->assertSame('accepted', $sync->run('wolt_catalog', false)['status']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://pos-integration-service.development.dev.woltapi.com/venues/catalog-fixture/items');
        Http::assertSentCount(3);
    }

    public function test_failed_or_rate_limited_response_never_advances_delta_baseline_or_leaks_provider_secrets(): void
    {
        config(['commerce.local_safe_mode' => false]);
        $this->readyConnection([$this->product('RETRY')]);
        Http::fake(['*' => Http::response('fixture-provider-secret-must-stay-private', 429, ['Retry-After' => '1800'])]);
        $sync = app(WoltSyncService::class);
        $failed = $sync->run('wolt_catalog', false);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(0, $failed['counts']['sent']);
        $this->assertStringNotContainsString('fixture-provider-secret-must-stay-private', json_encode($failed));
        $this->travel(16)->minutes();
        $this->assertSame('blocked', $sync->run('wolt_catalog', false)['status']);
        Http::assertSentCount(1);
        $this->assertSame(1, $sync->preview('wolt_catalog')['counts']['changed']);
    }

    public function test_conflicting_inactive_price_group_is_entirely_skipped_while_other_valid_catalog_rows_can_be_accepted(): void
    {
        config(['commerce.local_safe_mode' => false]);
        $first = $this->product('OLD-ONE', ['is_active' => false, 'stock_qty' => 0, 'base_price' => 45.13,
            'payload' => ['opencart' => ['product_id' => 2674, 'model' => 'CONFLICT-MODEL']]]);
        $second = $this->product('OLD-TWO', ['is_active' => false, 'stock_qty' => 0, 'base_price' => 59.73,
            'payload' => ['opencart' => ['product_id' => 2678, 'model' => 'CONFLICT-MODEL']]]);
        $valid = $this->product('VALID-MODEL', ['payload' => ['opencart' => ['product_id' => 9999, 'model' => 'VALID-MODEL']]]);
        $settings = $this->readyConnection([$first, $second, $valid]);
        $settings->save(array_replace($settings->options(), [
            'sku_field' => 'opencart_model', 'scope' => 'source_imported', 'price_basis' => 'stored_base',
            'availability_basis' => 'stock_quantity', 'include_vat' => false,
        ]));
        Http::fake(['*' => Http::response('', 202)]);

        $report = app(WoltSyncService::class)->run('wolt_catalog', false);

        $this->assertSame('accepted', $report['status']);
        $this->assertFalse($report['blocked']);
        $this->assertSame(1, $report['counts']['sent']);
        $this->assertSame(1, $report['counts']['skipped_conflicts']);
        $this->assertStringContainsString('CONFLICT-MODEL', implode(' ', $report['reasons']));
        Http::assertSent(fn ($request): bool => $request->data() === ['data' => [['sku' => 'VALID-MODEL', 'price' => 10000, 'enabled' => true]]]);
        $preview = app(WoltPayloadService::class)->preview('catalog');
        $this->assertSame([$first->id, $second->id], array_column($preview['conflicts'][0]['products'], 'product_id'));
        $this->assertSame([45.13, 59.73], array_column($preview['conflicts'][0]['products'], 'base_price'));
        $this->assertSame(0, app(WoltSyncService::class)->preview('wolt_catalog')['counts']['changed']);
        $inventory = app(WoltPayloadService::class)->preview('inventory');
        $this->assertSame(0, $inventory['skipped_conflicts']);
        $this->assertSame(['sku' => 'CONFLICT-MODEL', 'inventory' => 0], $inventory['payload']['data'][0]);

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('previewPayload', 'catalog')
            ->assertSee('CONFLICT-MODEL')->assertSee('45,13')->assertSee('59,73')
            ->assertSee(route('admin.products.edit', $first), false)->assertSee(route('admin.products.edit', $second), false);
    }

    public function test_expired_or_unknown_expiry_bearer_is_blocked_and_valid_bearer_uses_header_only(): void
    {
        config(['commerce.local_safe_mode' => false]);
        $settings = $this->readyConnection([$this->product('BEARER')]);
        $settings->save(array_replace($settings->options(), ['auth_type' => 'bearer']), ['access_token' => 'fixture-access-token']);
        $sync = app(WoltSyncService::class);
        $this->assertSame('blocked', $sync->run('wolt_catalog', false)['status']);
        $settings->save(array_replace($settings->options(), ['access_token_expires_at' => now()->subMinute()->toIso8601String()]));
        $this->assertSame('blocked', $sync->run('wolt_catalog', false)['status']);
        Http::assertNothingSent();
        $settings->save(array_replace($settings->options(), ['access_token_expires_at' => now()->addHour()->toIso8601String()]));
        Http::fake(['*' => Http::response('', 202)]);
        $this->assertSame('accepted', $sync->run('wolt_catalog', false)['status']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer fixture-access-token')
            && ! str_contains($request->url(), 'fixture-access-token'));
    }

    public function test_legacy_import_reads_scripts_as_text_and_preserves_real_distinct_curl_venues_not_unused_variables(): void
    {
        $directory = sys_get_temp_dir().'/watchline-wolt-fixture-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $inventorySource = <<<'PHP'
<?php
$username = 'fixture-user'; $password = 'fixture-password'; $venueId = 'unused-wrong-inventory';
$query = 'SELECT DISTINCT model as sku, quantity as inventory FROM oc_product';
curl_setopt($ch, CURLOPT_URL, 'https://pos-integration-service.wolt.com/venues/real-inventory-fixture/items/inventory');
base64_encode($username.':'.$password);
throw new RuntimeException('SOURCE MUST NEVER EXECUTE');
PHP;
        $catalogSource = <<<'PHP'
<?php
$woltUsername = 'fixture-user'; $woltPassword = 'fixture-password'; $venueId = 'real-catalog-fixture';
$query = 'SELECT DISTINCT model AS sku, quantity AS inventory, price FROM oc_product';
curl_setopt_array($ch, [CURLOPT_URL => "https://pos-integration-service.wolt.com/venues/$venueId/items"]);
base64_encode($woltUsername.':'.$woltPassword);
throw new RuntimeException('SOURCE MUST NEVER EXECUTE');
PHP;
        file_put_contents($directory.'/index.php', $inventorySource);
        file_put_contents($directory.'/inventory.php', $catalogSource);
        try {
            $importer = app(WoltLegacyConfigurationImporter::class);
            $result = $importer->import($directory);
            $options = app(WoltSettingsService::class)->options();
            $this->assertTrue($result['separate_venues']);
            $this->assertSame('real-catalog-fixture', $options['catalog_venue_id']);
            $this->assertSame('real-inventory-fixture', $options['inventory_venue_id']);
            $this->assertSame('source_imported', $options['scope']);
            $this->assertSame('opencart_model', $options['sku_field']);
            $this->assertSame('stored_base', $options['price_basis']);
            $this->assertSame('stock_quantity', $options['availability_basis']);
            $this->assertFalse($options['include_vat']);
            $this->assertFalse($options['send_enabled']);
            $this->assertFalse($options['auth_confirmed']);
            $this->assertFalse($options['mapping_confirmed']);
            $this->assertTrue(app(WoltSettingsService::class)->credentialsConfigured());
            $this->assertSame('unchanged', $importer->import($directory)['status']);
            $this->assertStringNotContainsString('fixture-password', json_encode($result));
            Http::assertNothingSent();
        } finally {
            unlink($directory.'/index.php');
            unlink($directory.'/inventory.php');
            rmdir($directory);
        }
    }

    private function readyConnection(array $products): WoltSettingsService
    {
        $settings = $this->configure($products, [
            'catalog_venue_id' => 'catalog-fixture', 'inventory_venue_id' => 'inventory-fixture',
            'mapping_confirmed' => true, 'auth_confirmed' => true, 'send_enabled' => true,
        ]);
        $settings->save($settings->options(), ['username' => 'fixture-user', 'password' => 'fixture-password']);

        return $settings;
    }

    private function product(string $sku, array $attributes = []): Product
    {
        $product = Product::create(array_replace([
            'code' => $sku, 'sku' => $sku, 'is_active' => true,
            'base_price' => 100, 'stock_qty' => 5,
        ], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => $sku, 'slug' => strtolower($sku)]);

        return $product;
    }

    private function configure(array $products, array $options = []): WoltSettingsService
    {
        $settings = app(WoltSettingsService::class);
        $settings->save(array_replace($settings->options(), ['product_ids' => array_map(fn (Product $product): int => $product->id, $products)], $options));

        return $settings;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::assign('superadmin')->to($user);

        return $user;
    }
}
