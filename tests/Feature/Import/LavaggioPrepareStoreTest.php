<?php

namespace Tests\Feature\Import;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LavaggioPrepareStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_preserves_the_staff_password_and_full_login_while_removing_imported_data(): void
    {
        $customer = User::factory()->create(['email' => 'tomislav@agmedia.hr', 'account_type' => 'customer']);
        $admin = User::factory()->create(['email' => 'tomislav@agmedia.hr', 'account_type' => 'staff']);
        User::factory()->create(['account_type' => 'staff']);
        $password = $admin->password;

        $manufacturer = DB::table('catalog_manufacturers')->insertGetId(['code' => 'IMPORTED-BRAND']);
        $category = DB::table('categories')->insertGetId(['code' => 'IMPORTED-CATEGORY']);
        $product = DB::table('products')->insertGetId(['code' => 'IMPORTED-PRODUCT', 'manufacturer_id' => $manufacturer]);
        DB::table('category_product')->insert(['product_id' => $product, 'category_id' => $category]);
        DB::table('product_translations')->insert(['product_id' => $product, 'locale' => 'hr', 'name' => 'Imported product', 'slug' => 'imported-product']);
        $order = DB::table('orders')->insertGetId(['order_number' => 'OLD-1', 'customer_name' => 'Imported', 'customer_email' => 'imported@example.test', 'user_id' => $customer->id]);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'name' => 'Imported product']);
        DB::table('newsletter_signups')->insert(['email' => 'imported@example.test']);
        DB::table('content_blog_posts')->insert(['code' => 'OLD-NEWS']);
        DB::table('payment_methods')->insert(['code' => 'IMPORTED-BANK', 'name' => 'Imported bank payment', 'settings' => '{"iban":"old-private-account"}']);
        $shipping = DB::table('shipping_methods')->insertGetId(['code' => 'IMPORTED-SHIPPING', 'name' => 'Imported shipping', 'free_over' => 70]);
        DB::table('shipping_method_rates')->insert(['shipping_method_id' => $shipping, 'price' => 9]);
        DB::table('tax_rates')->insert(['code' => 'DEFAULT-VAT', 'name' => 'VAT', 'rate' => 25]);
        DB::table('currencies')->insert(['code' => 'EUR', 'name' => 'Euro']);
        DB::table('languages')->insert(['code' => 'hr', 'name' => 'Hrvatski']);
        DB::table('order_statuses')->insert(['code' => 'NEW-ORDER', 'name' => 'New order']);
        DB::table('system_settings')->insert(['key' => 'store_brand_name', 'value' => '"Lavaggio"']);
        $rolesBefore = DB::table('roles')->count();
        $abilitiesBefore = DB::table('abilities')->count();
        $infrastructureBefore = [];
        foreach (['tax_rates', 'currencies', 'languages', 'regions', 'geo_zones', 'geo_zone_countries', 'order_statuses'] as $table) {
            $infrastructureBefore[$table] = DB::table($table)->get()->toJson();
        }

        $this->assertSame(0, Artisan::call('lavaggio:prepare-store', ['--execute' => true]));
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($admin->id, User::sole()->id);
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertTrue($admin->fresh()->isA('superadmin'));
        $this->assertSame($rolesBefore, DB::table('roles')->count());
        $this->assertSame($abilitiesBefore, DB::table('abilities')->count());
        $this->assertDatabaseHas('system_settings', ['key' => 'store_brand_name']);
        foreach (['products', 'product_translations', 'categories', 'category_product', 'catalog_manufacturers', 'orders', 'order_items', 'newsletter_signups', 'content_blog_posts', 'user_legacy_credentials', 'payment_methods', 'shipping_methods', 'shipping_method_rates'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach ($infrastructureBefore as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson());
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        Auth::logout();
        Volt::test('pages.auth.login')->set('form.email', 'tomislav@agmedia.hr')
            ->set('form.password', 'password')->call('login')->assertHasNoErrors();
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin/dashboard')->assertOk();
    }

    public function test_preview_and_missing_admin_do_not_delete_data(): void
    {
        User::factory()->create(['email' => 'tomislav@agmedia.hr', 'account_type' => 'staff']);
        DB::table('products')->insert(['code' => 'KEEP-UNTIL-EXECUTED']);
        $this->assertSame(0, Artisan::call('lavaggio:prepare-store'));
        $this->assertDatabaseCount('products', 1);

        DB::table('users')->delete();
        $this->assertSame(1, Artisan::call('lavaggio:prepare-store', ['--execute' => true]));
        $this->assertDatabaseCount('products', 1);
    }

    public function test_source_and_nonlocal_database_settings_are_refused_before_access(): void
    {
        config(['database.default' => 'watchline_source']);
        $this->assertSame(1, Artisan::call('lavaggio:prepare-store', ['--execute' => true]));
        $this->assertStringContainsString('restricted to the local Lavaggio SQLite', Artisan::output());

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => '/tmp/watchline-new/database/database.sqlite']);
        $this->assertSame(1, Artisan::call('lavaggio:prepare-store', ['--execute' => true]));
        $this->assertStringContainsString('Cleanup refused', Artisan::output());

        config(['database.connections.sqlite.database' => ':memory:']);
        app()->instance('env', 'production');
        $this->assertSame(1, Artisan::call('lavaggio:prepare-store', ['--execute' => true]));
        $this->assertStringContainsString('Cleanup refused', Artisan::output());
        app()->instance('env', 'testing');
    }
}
