<?php

namespace Tests\Feature\Import;

use App\Models\Sales\GiftVoucher;
use App\Models\User;
use App\Services\Import\WatchlineLegacySalesImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WatchlineLegacySalesImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'watchline-sales-source-');
        config(['database.connections.watchline_sales_test' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('watchline_sales_test');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('watchline_sales_test');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_dry_run_never_writes_and_same_database_is_rejected(): void
    {
        $this->source('coupon', [$this->coupon()]);
        $stats = app(WatchlineLegacySalesImportService::class)->import('watchline_sales_test', true);
        $this->assertSame(1, $stats['source_coupon']);
        $this->assertDatabaseCount('catalog_actions', 0);
        $this->assertDatabaseCount('herrera_source_records', 0);
        $this->expectException(\RuntimeException::class);
        app(WatchlineLegacySalesImportService::class)->import(config('database.default'), true);
    }

    public function test_coupons_retain_limits_history_and_do_not_silently_enable_unsupported_usage_rules(): void
    {
        $user = User::factory()->create(['account_type' => 'customer']);
        $profile = DB::table('user_profiles')->insertGetId(['user_id' => $user->id, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->order('SALE');
        $product = DB::table('products')->insertGetId(['code' => 'product-test', 'sku' => 'PRODUCT', 'base_price' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $this->map('customer', 7, $user->id);
        $this->map('order', 9, $order);
        $this->map('product', 30, $product);
        $this->source('coupon', [$this->coupon()]);
        $this->source('coupon_product', [['coupon_product_id' => 3, 'coupon_id' => 4, 'product_id' => 30]]);
        $this->source('coupon_history', [['coupon_history_id' => 5, 'coupon_id' => 4, 'customer_id' => 7, 'order_id' => 9, 'amount' => '-10.00', 'date_added' => '2026-10-01 10:00:00']]);

        $importer = app(WatchlineLegacySalesImportService::class);
        $importer->import('watchline_sales_test');
        $this->assertDatabaseHas('catalog_actions', ['scope' => 'cart', 'coupon_code' => 'TEST10', 'discount_value' => 10, 'usage_limit' => 100, 'usage_limit_per_user' => 1, 'used_count' => 1, 'is_active' => 0]);
        $this->assertDatabaseHas('catalog_action_targets', ['target_type' => 'product', 'target_id' => $product]);
        $this->assertSame('2027-12-31 23:59:59', DB::table('catalog_actions')->value('ends_at'));
        $payload = json_decode(DB::table('orders')->where('id', $order)->value('payload'), true);
        $this->assertEquals(5, $payload['watchline_legacy_sales']['coupons'][5]['coupon_history_id']);
        $this->assertArrayHasKey('coupons', json_decode(DB::table('user_profiles')->where('id', $profile)->value('payload'), true)['watchline_legacy_sales']);
        DB::table('catalog_actions')->update(['discount_value' => 12]);
        $importer->import('watchline_sales_test');
        $this->assertDatabaseHas('catalog_actions', ['discount_value' => 12]);
        $this->assertDatabaseCount('catalog_actions', 1);
        $this->assertDatabaseCount('catalog_action_targets', 1);
        $this->assertSame(1, DB::connection('watchline_sales_test')->table('oc_coupon_history')->count());
    }

    public function test_duplicate_source_coupon_product_rows_create_one_target_without_an_unmapped_warning(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'duplicate-test', 'sku' => 'DUPLICATE', 'base_price' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $this->map('product', 30, $product);
        $this->source('coupon', [$this->coupon()]);
        $this->source('coupon_product', [
            ['coupon_product_id' => 3, 'coupon_id' => 4, 'product_id' => 30],
            ['coupon_product_id' => 4, 'coupon_id' => 4, 'product_id' => 30],
        ]);

        $importer = app(WatchlineLegacySalesImportService::class);
        $importer->import('watchline_sales_test');
        $importer->import('watchline_sales_test');

        $this->assertDatabaseCount('catalog_actions', 1);
        $this->assertDatabaseCount('catalog_action_targets', 1);
        $this->assertDatabaseHas('catalog_action_targets', ['target_type' => 'product', 'target_id' => $product, 'sort_order' => 0]);
        $payload = json_decode(DB::table('catalog_actions')->value('payload'), true);
        $this->assertSame(['coupon_usage_limits_not_enforced'], $payload['import_requires_review']);
        $this->assertSame(2, DB::table('herrera_source_records')->where('source_table', 'coupon_product')->count());
        $this->assertSame(2, DB::connection('watchline_sales_test')->table('oc_coupon_product')->count());
    }

    public function test_negative_voucher_history_exhausts_the_balance_and_plaintext_code_is_not_archived(): void
    {
        $purchase = $this->order('PURCHASE');
        $redeemed = $this->order('REDEEMED');
        $this->map('order', 9, $purchase);
        $this->map('order', 10, $redeemed);
        $this->source('voucher', [['voucher_id' => 2, 'order_id' => 9, 'code' => 'GIFT123', 'from_name' => 'Buyer', 'from_email' => 'buyer@example.test', 'to_name' => 'Recipient', 'to_email' => 'recipient@example.test', 'voucher_theme_id' => 1, 'message' => 'Gift', 'amount' => '150.0000', 'status' => 1, 'date_added' => '2026-09-01 10:00:00']]);
        $this->source('voucher_history', [['voucher_history_id' => 8, 'voucher_id' => 2, 'order_id' => 10, 'amount' => '-150.0000', 'date_added' => '2026-09-02 10:00:00']]);

        $importer = app(WatchlineLegacySalesImportService::class);
        $importer->import('watchline_sales_test');
        $voucher = GiftVoucher::query()->sole();
        $this->assertSame('GIFT123', $voucher->code);
        $this->assertNotSame('GIFT123', $voucher->code_ciphertext);
        $this->assertSame('0.00', $voucher->balance);
        $this->assertSame('exhausted', $voucher->status);
        $this->assertDatabaseHas('gift_voucher_redemptions', ['gift_voucher_id' => $voucher->id, 'order_id' => $redeemed, 'amount' => 150, 'status' => 'redeemed']);
        $this->assertStringNotContainsString('GIFT123', DB::table('herrera_source_records')->where('source_table', 'voucher')->value('payload'));
        $voucher->update(['message' => 'Edited locally']);
        $importer->import('watchline_sales_test');
        $this->assertDatabaseCount('gift_vouchers', 1);
        $this->assertDatabaseCount('gift_voucher_redemptions', 1);
        $this->assertSame('Edited locally', $voucher->fresh()->message);
        $this->assertSame('0.00', $voucher->fresh()->balance);
        Mail::assertNothingSent();
    }

    public function test_returns_keep_original_context_even_when_the_original_order_is_missing(): void
    {
        $order = $this->order('RETURN');
        $this->map('order', 9, $order);
        $this->source('return', [
            ['return_id' => 2, 'order_id' => 9, 'customer_id' => 0, 'product_id' => 30, 'product' => 'Watch', 'quantity' => 1, 'return_action_id' => 1, 'return_reason_id' => 1, 'return_status_id' => 1, 'refund_iban' => 'TEST-IBAN', 'date_added' => '2026-10-01 10:00:00'],
            ['return_id' => 3, 'order_id' => 999, 'customer_id' => 0, 'product_id' => 30, 'product' => 'Watch', 'quantity' => 1, 'return_action_id' => 1, 'return_reason_id' => 1, 'return_status_id' => 1, 'refund_iban' => '', 'date_added' => '2026-10-01 10:00:00'],
        ]);
        $this->source('return_status', [['return_status_id' => 1, 'language_id' => 2, 'name' => 'Pending']]);
        $this->source('return_history', [['return_history_id' => 4, 'return_id' => 2, 'return_status_id' => 1, 'notify' => 0, 'comment' => 'Historical note', 'date_added' => '2026-10-01 11:00:00']]);

        $importer = app(WatchlineLegacySalesImportService::class);
        $importer->import('watchline_sales_test');
        $payload = json_decode(DB::table('orders')->where('id', $order)->value('payload'), true);
        $snapshot = $payload['watchline_legacy_sales']['returns'][2];
        $this->assertSame('TEST-IBAN', $snapshot['opencart']['refund_iban']);
        $this->assertSame('Historical note', $snapshot['history'][0]['comment']);
        $this->assertSame('Pending', $snapshot['return_status'][2]);
        $this->assertSame(2, DB::table('herrera_source_records')->where('source_table', 'return')->count());
        $this->assertDatabaseCount('contract_withdrawals', 0);
        $payload['watchline_legacy_sales']['returns'][2]['admin_note'] = 'Keep';
        DB::table('orders')->where('id', $order)->update(['payload' => json_encode($payload)]);
        $importer->import('watchline_sales_test');
        $this->assertSame('Keep', json_decode(DB::table('orders')->where('id', $order)->value('payload'), true)['watchline_legacy_sales']['returns'][2]['admin_note']);
        $this->assertSame(2, DB::table('herrera_source_records')->where('source_table', 'return')->count());
    }

    private function source(string $table, array $rows): void
    {
        Schema::connection('watchline_sales_test')->create('oc_'.$table, function (Blueprint $blueprint) use ($rows): void {
            foreach (array_keys($rows[0]) as $column) {
                $blueprint->text($column)->nullable();
            }
        });
        DB::connection('watchline_sales_test')->table('oc_'.$table)->insert($rows);
    }

    private function map(string $entity, int $source, int $target): void
    {
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $source, 'target_id' => $target, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function order(string $number): int
    {
        return DB::table('orders')->insertGetId(['order_number' => $number, 'customer_name' => 'Test', 'customer_email' => 'customer@example.test', 'currency_code' => 'EUR', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function coupon(): array
    {
        return ['coupon_id' => 4, 'name' => 'Test coupon', 'code' => 'TEST10', 'type' => 'P', 'discount' => 10, 'logged' => 0, 'shipping' => 0, 'total' => 40, 'date_start' => '2026-01-01', 'date_end' => '2027-12-31', 'uses_total' => 100, 'uses_customer' => 1, 'status' => 1, 'date_added' => '2026-01-01 10:00:00'];
    }
}
