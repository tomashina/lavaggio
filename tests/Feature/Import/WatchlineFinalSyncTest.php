<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\LegacyCredential;
use App\Services\Import\HerreraOpenCartImportService;
use App\Services\Import\WatchlineFinalSyncSafety;
use App\Services\Import\WatchlineFinalSyncService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SQLite3;
use Tests\TestCase;

class WatchlineFinalSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    private array $candidateRuns = [];

    private array $reports = [];

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = DB::getDefaultConnection();
        config(['storefront.home' => 'watchline', 'commerce.b2c_only' => true, 'commerce.local_safe_mode' => true]);
        Mail::fake();
        Http::preventStrayRequests();
        foreach (['wl_baseline', 'wl_fresh'] as $name) {
            $this->files[$name] = tempnam(sys_get_temp_dir(), 'watchline-frozen-');
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $this->files[$name], 'prefix' => '', 'foreign_key_constraints' => true, 'import_order_prefix' => 'OC-WATCHLINE-']]);
            DB::purge($name);
        }
        $this->fixture();
        app(HerreraOpenCartImportService::class)->import('wl_baseline', 'oc_', false, ['catalog', 'customers', 'billing_addresses', 'orders', 'seo', 'relations']);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        config(['database.default' => $this->originalDefault]);
        foreach (['wl_baseline', 'wl_fresh', 'wl_target', 'watchline_final_candidate', 'wl_inspect'] as $name) {
            DB::purge($name);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach ($this->candidateRuns as $run) {
            File::deleteDirectory(storage_path('app/private/watchline/final-sync/'.$run));
        }
        foreach ($this->reports as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_plan_is_read_only_with_stable_ids_and_private_aggregate_extension_deltas(): void
    {
        $this->copySource();
        DB::connection('wl_fresh')->table('oc_product')->where('product_id', 30)->update(['quantity' => 19]);
        DB::connection('wl_fresh')->table('oc_product')->insert($this->productRow(32));
        DB::connection('wl_fresh')->table('oc_review')->where('review_id', 1)->update(['text' => 'Changed private review']);
        DB::connection('wl_fresh')->table('oc_user')->insert(['user_id' => 2, 'username' => 'private-new-staff', 'password' => 'private-password-marker']);
        DB::connection('wl_fresh')->table('oc_product_special')->insert(['product_special_id' => 2, 'product_id' => 32, 'price' => '80']);
        $this->switchToFileTarget();
        $before = hash_file('sha256', DB::connection()->getDatabaseName());
        $report = $this->plan();
        $this->assertSame('planned', $report['status']);
        $this->assertSame(1, $report['table_deltas']['product']['new']);
        $this->assertSame(1, $report['table_deltas']['product']['changed']);
        $this->assertSame(1, $report['pending_domains']['staff']['new']);
        $this->assertSame(1, $report['pending_domains']['supplement']['changed']);
        $this->assertSame(1, $report['pending_domains']['promotions']['new']);
        $this->assertFalse($report['ready_for_live']);
        $this->assertSame($before, hash_file('sha256', DB::connection()->getDatabaseName()));
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['source@example.test', 'private-new-staff', 'private-password-marker', 'Changed private review', 'old-salt'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_rehearsal_merges_new_and_changed_core_twice_preserving_native_data_and_all_originals(): void
    {
        Storage::fake('public');
        $product = Product::query()->firstOrFail();
        $product->update(['payload' => array_replace($product->payload, ['storefront' => ['is_new' => true], 'curated' => ['keep' => true]])]);
        $product->translations()->where('locale', 'hr')->update(['meta_title' => 'Native SEO', 'meta_description' => 'Native SEO description']);
        $media = $product->addMedia(UploadedFile::fake()->image('curated.jpg', 20, 20))->toMediaCollection('product_main');
        $mediaBytes = file_get_contents($media->getPath());
        app(SystemSettingsService::class)->put('store_brand_name', 'Native Watchline settings');
        $customer = User::query()->where('email', 'source@example.test')->firstOrFail();
        $customer->update(['password' => Hash::make('upgraded-native-password')]);
        LegacyCredential::where('user_id', $customer->id)->firstOrFail()->retire();
        $upgraded = $customer->fresh()->password;
        $localUser = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        DB::table('orders')->insert(['order_number' => 'NATIVE-LOCAL-1', 'source' => 'web', 'customer_name' => 'Native order', 'customer_email' => 'native-order@example.test', 'grand_total' => 123.45, 'admin_note' => 'Preserve native workflow']);
        $this->copySource();
        $fresh = DB::connection('wl_fresh');
        $fresh->table('oc_product')->where('product_id', 30)->update(['quantity' => 19, 'price' => '120']);
        $fresh->table('oc_customer')->where('customer_id', 7)->update(['password' => str_repeat('b', 40)]);
        $fresh->table('oc_product')->insert($this->productRow(32));
        $fresh->table('oc_product_description')->insert(['product_id' => 32, 'language_id' => 2, 'name' => 'New watch', 'description' => 'New factual description', 'meta_title' => 'New watch SEO', 'meta_description' => 'New watch description']);
        $fresh->table('oc_product_to_category')->insert(['product_id' => 32, 'category_id' => 10]);
        $fresh->table('oc_product_related')->insert(['product_id' => 30, 'related_id' => 32]);
        $fresh->table('oc_customer')->insert($this->customerRow(8));
        $fresh->table('oc_order')->insert($this->orderRow(501, 8));
        $fresh->table('oc_order_product')->insert(['order_product_id' => 2, 'order_id' => 501, 'product_id' => 32, 'model' => 'SKU-32', 'name' => 'New watch', 'price' => '100', 'quantity' => 1, 'total' => '100', 'tax' => '0']);
        $fresh->table('oc_order_total')->insert(['order_total_id' => 2, 'order_id' => 501, 'code' => 'sub_total', 'title' => 'Subtotal', 'value' => '100', 'sort_order' => 0]);
        $fresh->table('oc_order_history')->insert(['order_history_id' => 2, 'order_id' => 501, 'order_status_id' => 1, 'comment' => 'Historical new order', 'notify' => 1, 'date_added' => '2026-10-24 12:00:00']);
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertSame('core_rehearsed_review_required', $report['status'], json_encode($report));
        $this->assertTrue($report['core_rehearsal_passed']);
        $this->assertTrue($report['repeatable']);
        $this->assertTrue($report['original_unchanged']);
        $this->assertTrue($report['sources_unchanged']);
        $this->assertTrue($report['protected_data_preserved']);
        $this->assertFalse($report['ready_for_live']);
        $this->assertSame('wl_target', DB::getDefaultConnection());
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(2, DB::table('orders')->count());
        $candidate = $this->inspect($report);
        $this->assertSame(2, $candidate->table('products')->count());
        $this->assertSame(3, $candidate->table('orders')->count());
        $this->assertSame(19, $candidate->table('products')->where('id', $product->id)->value('stock_qty'));
        $this->assertSame(12, $candidate->table('products')->where('code', 'herrera-oc-product-32')->value('stock_qty'));
        $this->assertEquals(120, $candidate->table('products')->where('id', $product->id)->value('base_price'));
        $this->assertSame('Native SEO', $candidate->table('product_translations')->where('product_id', $product->id)->value('meta_title'));
        $this->assertTrue(data_get(json_decode($candidate->table('products')->where('id', $product->id)->value('payload'), true), 'curated.keep'));
        $this->assertSame($upgraded, $candidate->table('users')->where('id', $customer->id)->value('password'));
        $this->assertFalse((bool) $candidate->table('user_legacy_credentials')->where('user_id', $customer->id)->value('enabled'));
        $this->assertSame($localUser->password, $candidate->table('users')->where('id', $localUser->id)->value('password'));
        $newCustomer = $candidate->table('users')->where('email', 'new8@example.test')->first();
        $this->assertSame('customer', $newCustomer->account_type);
        $this->assertSame(0, $candidate->table('assigned_roles')->where('entity_id', $newCustomer->id)->count());
        $this->assertSame($newCustomer->id, $candidate->table('orders')->where('order_number', 'OC-WATCHLINE-501')->value('user_id'));
        $this->assertEquals(123.45, $candidate->table('orders')->where('order_number', 'NATIVE-LOCAL-1')->value('grand_total'));
        $this->assertSame('Preserve native workflow', $candidate->table('orders')->where('order_number', 'NATIVE-LOCAL-1')->value('admin_note'));
        $this->assertSame($mediaBytes, file_get_contents($media->getPath()));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_native_and_source_overlap_blocks_readiness_and_keeps_native_price(): void
    {
        Product::query()->firstOrFail()->update(['base_price' => 111]);
        $this->copySource();
        DB::connection('wl_fresh')->table('oc_product')->where('product_id', 30)->update(['price' => '125']);
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertSame('rehearsal_blocked', $report['status']);
        $this->assertGreaterThan(0, $report['core_conflicts']);
        $this->assertEquals(111, $this->inspect($report)->table('products')->value('base_price'));
        $this->assertEquals(111, DB::table('products')->value('base_price'));
    }

    public function test_wrong_baseline_fails_before_a_candidate_is_created(): void
    {
        DB::connection('wl_baseline')->table('oc_product')->update(['price' => '999']);
        $this->copySource();
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('baseline_does_not_match_imported_core', $report['blockers']);
        $this->assertArrayNotHasKey('candidate', $report);
    }

    public function test_removed_b2c_customer_requires_review_before_any_candidate_or_verifier_change(): void
    {
        $this->copySource();
        DB::connection('wl_fresh')->table('oc_customer')->delete();
        DB::connection('wl_fresh')->table('oc_customer')->insert($this->customerRow(8));
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('removed_customer_identity_requires_review', $report['blockers']);
        $this->assertArrayNotHasKey('candidate', $report);
        $this->assertTrue((bool) DB::table('user_legacy_credentials')->value('enabled'));
    }

    public function test_native_newsletter_privacy_choice_is_not_overridden_by_source_preference_change(): void
    {
        // The old source baseline was opt-out. A native opt-out remains authoritative.
        DB::connection('wl_baseline')->table('oc_customer')->update(['newsletter' => 0]);
        app(HerreraOpenCartImportService::class)->import('wl_baseline', 'oc_', false, ['customers']);
        $this->copySource();
        DB::connection('wl_fresh')->table('oc_customer')->update(['newsletter' => 1]);
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertTrue($report['protected_data_preserved']);
        $this->assertTrue($report['pending_domains']['supplement']['requires_review']);
        $this->assertFalse((bool) $this->inspect($report)->table('user_profiles')->value('newsletter_opt_in'));
        Mail::assertNothingSent();
    }

    public function test_native_historical_aggregate_override_is_preserved_and_requires_review(): void
    {
        DB::table('orders')->where('order_number', 'OC-WATCHLINE-500')->update(['subtotal' => 777]);
        $this->copySource();
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertSame('rehearsal_blocked', $report['status']);
        $this->assertSame(1, $report['native_derived_order_override_fields']);
        $this->assertTrue($report['protected_data_preserved']);
        $this->assertFalse($report['reconciliation']['historical_order_derived_totals']);
        $this->assertEquals(777, $this->inspect($report)->table('orders')->where('order_number', 'OC-WATCHLINE-500')->value('subtotal'));
        $this->assertEquals(777, DB::table('orders')->where('order_number', 'OC-WATCHLINE-500')->value('subtotal'));
    }

    public function test_new_duplicate_sku_gets_stable_source_suffix_and_does_not_create_a_duplicate_on_repeat(): void
    {
        $this->copySource();
        DB::connection('wl_fresh')->table('oc_product')->insert(array_replace($this->productRow(32), ['sku' => 'SKU-30']));
        DB::connection('wl_fresh')->table('oc_product_description')->insert(['product_id' => 32, 'language_id' => 2, 'name' => 'Another watch', 'description' => '', 'meta_title' => '', 'meta_description' => '']);
        $this->switchToFileTarget();
        $plan = $this->plan();
        $this->assertSame(1, $plan['identity_risks']['duplicate_product_identifiers']);
        $report = $this->rehearse();
        $this->assertTrue($report['repeatable']);
        $candidate = $this->inspect($report);
        $this->assertSame(2, $candidate->table('products')->count());
        $this->assertSame('SKU-30-oc-32', $candidate->table('products')->where('code', 'herrera-oc-product-32')->value('sku'));
    }

    public function test_mapping_a_customer_to_staff_is_blocked_before_copying(): void
    {
        $staff = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        DB::table('herrera_import_maps')->where('entity', 'customer')->update(['target_id' => $staff->id]);
        $this->copySource();
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['identity_risks']['mapped_customer_is_staff']);
        $this->assertArrayNotHasKey('candidate', $report);
    }

    public function test_command_defaults_to_aggregate_plan_without_import_or_activation(): void
    {
        $this->copySource();
        $this->switchToFileTarget();
        $beforeRuns = DB::table('herrera_import_runs')->count();
        $exit = Artisan::call('watchline:final-sync', [
            '--baseline-connection' => 'wl_baseline', '--source-connection' => 'wl_fresh',
            '--baseline-date' => '2026-10-23', '--source-date' => '2026-10-24',
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertSame($beforeRuns, DB::table('herrera_import_runs')->count());
        $this->assertStringNotContainsString('source@example.test', $output);
        $this->assertSame(1, preg_match('~Private aggregate report: (watchline/final-sync/plan-[^\s]+\.json)~', $output, $match));
        $this->reports[] = $path = storage_path('app/private/'.$match[1]);
        $report = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['ready_for_live']);
        $this->assertSame('read-only-plan', $report['mode']);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertArrayNotHasKey('candidate', $report);
    }

    #[DataProvider('unsafeCandidateConfigs')]
    public function test_guard_rejects_current_production_and_alias_candidates(array $config): void
    {
        $this->expectException(RuntimeException::class);
        WatchlineFinalSyncSafety::assertCandidate($config);
    }

    public static function unsafeCandidateConfigs(): array
    {
        return [
            'current SQLite' => [['driver' => 'sqlite', 'database' => '/Users/tomek/Herd/watchline-new/database/database.sqlite']],
            'temporary alias' => [['driver' => 'sqlite', 'database' => '/tmp/watchline-candidate.sqlite']],
            'production mysql' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'watchline_live']],
            'split reader' => [['driver' => 'sqlite', 'database' => '/tmp/x.sqlite', 'read' => ['database' => '/tmp/y.sqlite']]],
        ];
    }

    public function test_signed_candidate_cannot_be_redirected_to_original_or_forged(): void
    {
        $this->copySource();
        $this->switchToFileTarget();
        $report = $this->rehearse();
        $path = storage_path('app/private/'.$report['candidate']);
        $proof = dirname($path).'/candidate.json';
        $config = ['driver' => 'sqlite', 'database' => $path, 'watchline_candidate_proof' => $proof];
        WatchlineFinalSyncSafety::assertCandidate($config);
        $document = json_decode(file_get_contents($proof), true);
        $document['original'] = $path;
        file_put_contents($proof, json_encode($document));
        $this->expectException(RuntimeException::class);
        WatchlineFinalSyncSafety::assertCandidate($config);
    }

    public function test_generic_final_sync_cannot_use_current_target_with_a_watchline_mysql_source(): void
    {
        $this->switchToFileTarget();
        // A lazy connection proves the write guard runs before any source PDO is opened.
        config(['database.connections.wl_guarded_mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'watchline_snapshot_20261024', 'username' => 'unused', 'password' => '',
        ]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only a new private rehearsal SQLite candidate');
        app(HerreraOpenCartImportService::class)->import('wl_guarded_mysql', 'oc_', true, ['catalog'], finalSync: true);
    }

    public function test_the_same_source_alias_cannot_be_called_a_new_fresh_snapshot(): void
    {
        $this->switchToFileTarget();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different immutable database identities');
        app(WatchlineFinalSyncService::class)->plan('wl_baseline', 'wl_baseline', 'oc_', '2026-10-23', '2026-10-24');
    }

    private function plan(): array
    {
        return app(WatchlineFinalSyncService::class)->plan('wl_baseline', 'wl_fresh', 'oc_', '2026-10-23', '2026-10-24');
    }

    private function rehearse(): array
    {
        $report = app(WatchlineFinalSyncService::class)->rehearse('wl_baseline', 'wl_fresh', 'oc_', '2026-10-23', '2026-10-24');
        if (isset($report['run_id'])) {
            $this->candidateRuns[] = $report['run_id'];
        }

        return $report;
    }

    private function inspect(array $report)
    {
        config(['database.connections.wl_inspect' => ['driver' => 'sqlite', 'database' => storage_path('app/private/'.$report['candidate']), 'prefix' => '']]);
        DB::purge('wl_inspect');

        return DB::connection('wl_inspect');
    }

    private function copySource(): void
    {
        $from = new SQLite3($this->files['wl_baseline'], SQLITE3_OPEN_READONLY);
        $to = new SQLite3($this->files['wl_fresh']);
        $from->backup($to);
        $to->close();
        $from->close();
        DB::purge('wl_fresh');
    }

    private function switchToFileTarget(): void
    {
        $path = $this->files['wl_target'] = tempnam(sys_get_temp_dir(), 'watchline-target-fixture-');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
            $pdo->exec($table->sql);
            foreach (DB::table($table->name)->cursor() as $row) {
                $data = (array) $row;
                $columns = implode(',', array_map(fn ($column) => '"'.$column.'"', array_keys($data)));
                $statement = $pdo->prepare('INSERT INTO "'.$table->name.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($data), '?')).')');
                $statement->execute(array_values($data));
            }
        }
        $pdo = null;
        config(['database.connections.wl_target' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('wl_target');
        DB::setDefaultConnection('wl_target');
        config(['database.default' => 'wl_target']);
    }

    private function fixture(): void
    {
        $this->source('language', [['language_id' => 2, 'code' => 'hr-hr']]);
        $this->source('country', [['country_id' => 1, 'iso_code_2' => 'HR']]);
        $this->source('category', [['category_id' => 10, 'parent_id' => 0, 'status' => 1, 'sort_order' => 0, 'top' => 1, 'image' => '']]);
        $this->source('category_description', [['category_id' => 10, 'language_id' => 2, 'name' => 'Satovi', 'description' => '', 'meta_title' => '', 'meta_description' => '']]);
        $this->source('product', [$this->productRow(30)]);
        $this->source('product_description', [['product_id' => 30, 'language_id' => 2, 'name' => 'Source watch', 'description' => 'Source factual description', 'meta_title' => 'Source SEO', 'meta_description' => 'Source description']]);
        $this->source('product_to_category', [['product_id' => 30, 'category_id' => 10]]);
        $this->source('product_related', [], ['product_id', 'related_id']);
        $this->source('customer_group', [['customer_group_id' => 4, 'sort_order' => 1]]);
        $this->source('customer', [$this->customerRow(7)]);
        $this->source('address', [], ['address_id', 'customer_id']);
        $this->source('order_status', [['order_status_id' => 1, 'language_id' => 2, 'name' => 'Complete']]);
        $this->source('order', [$this->orderRow(500, 7)]);
        $this->source('order_product', [['order_product_id' => 1, 'order_id' => 500, 'product_id' => 30, 'model' => 'SKU-30', 'name' => 'Source watch', 'price' => '100', 'quantity' => 1, 'total' => '100', 'tax' => '0']]);
        $this->source('order_total', [['order_total_id' => 1, 'order_id' => 500, 'code' => 'sub_total', 'title' => 'Subtotal', 'value' => '100', 'sort_order' => 0]]);
        $this->source('order_history', [['order_history_id' => 1, 'order_id' => 500, 'order_status_id' => 1, 'comment' => 'Source historical status', 'notify' => 1, 'date_added' => '2026-10-23 12:00:00']]);
        $this->source('review', [['review_id' => 1, 'text' => 'Private existing review']]);
        $this->source('user', [['user_id' => 1, 'username' => 'private-original-staff', 'password' => 'private-password']]);
        $this->source('product_special', [['product_special_id' => 1, 'product_id' => 30, 'price' => '90']]);
    }

    private function source(string $table, array $rows, array $emptyColumns = []): void
    {
        Schema::connection('wl_baseline')->create('oc_'.$table, function (Blueprint $schema) use ($rows, $emptyColumns): void {
            foreach (array_keys($rows[0] ?? array_fill_keys($emptyColumns, null)) as $column) {
                $schema->text($column)->nullable();
            }
        });
        if ($rows !== []) {
            DB::connection('wl_baseline')->table('oc_'.$table)->insert($rows);
        }
    }

    private function productRow(int $id): array
    {
        return ['product_id' => $id, 'model' => 'SKU-'.$id, 'sku' => 'SKU-'.$id, 'ean' => '', 'upc' => '', 'manufacturer_id' => 0, 'tax_class_id' => 0, 'status' => 1, 'price' => '100', 'quantity' => 12, 'minimum' => 1, 'weight' => '1', 'length' => '10', 'width' => '5', 'height' => '3', 'image' => 'catalog/watch.jpg'];
    }

    private function customerRow(int $id): array
    {
        return ['customer_id' => $id, 'customer_group_id' => 4, 'firstname' => 'Source', 'lastname' => 'Customer', 'email' => $id === 7 ? 'source@example.test' : 'new'.$id.'@example.test', 'telephone' => '+38510000000', 'password' => str_repeat('a', 40), 'salt' => 'old-salt', 'token' => 'private-token', 'newsletter' => 1, 'address_id' => 0, 'custom_field' => '{}', 'status' => 1, 'date_added' => '2020-01-01 12:00:00'];
    }

    private function orderRow(int $id, int $customer): array
    {
        $row = ['order_id' => $id, 'order_status_id' => 1, 'customer_id' => $customer, 'language_id' => 2, 'currency_code' => 'EUR', 'currency_value' => '1', 'firstname' => 'Source', 'lastname' => 'Customer', 'email' => $customer === 7 ? 'source@example.test' : 'new'.$customer.'@example.test', 'telephone' => '+38510000000', 'total' => '100', 'comment' => 'Historical order', 'date_added' => '2026-10-23 12:00:00'];
        foreach (['payment', 'shipping'] as $prefix) {
            foreach (['firstname', 'lastname', 'company', 'address_1', 'address_2', 'postcode', 'city', 'zone', 'code', 'method'] as $field) {
                $row[$prefix.'_'.$field] = 'Synthetic '.$field;
            }
            $row[$prefix.'_country_id'] = 1;
        }

        return $row;
    }
}
