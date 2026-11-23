<?php

namespace Tests\Feature\Feeds;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioStockFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_link_serves_live_guest_prices_and_only_local_stock_for_each_sku(): void
    {
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'app.locale' => 'hr']);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $rate = TaxRate::create(['code' => 'PDV25', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true]);
        $product = Product::create(['sku' => '00032', 'code' => 'PRF32', 'barcode' => '0123456789012',
            'base_price' => 8, 'stock_qty' => 0, 'supplier_stock_qty' => 85, 'delivery_mode' => 'supplier',
            'weight_kg' => 0.245, 'tax_rate_id' => $rate->id, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Parfem & <cvijeće>', 'slug' => 'parfem']);
        $withVariant = Product::create(['code' => 'VAR', 'sku' => 'PARENT', 'base_price' => 12, 'stock_qty' => 83, 'tax_rate_id' => $rate->id, 'is_active' => true]);
        $withVariant->translations()->create(['locale' => 'hr', 'name' => 'Gel', 'slug' => 'gel']);
        $option = Option::create(['code' => 'scent', 'type' => 'select', 'is_active' => true]);
        $value = $option->values()->create(['code' => 'ruza', 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'name' => 'Ruža', 'slug' => 'ruza']);
        $withVariant->optionValues()->create(['option_value_id' => $value->id, 'mode' => 'single', 'sku' => 'VAR-R',
            'stock_qty' => 2, 'price_override' => 16, 'is_active' => true, 'combination_hash' => 'ruza']);
        Product::create(['sku' => 'HIDDEN', 'code' => 'HIDDEN', 'base_price' => 2, 'is_active' => false]);
        CatalogAction::create(['code' => 'SALE', 'scope' => 'product', 'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 10,
            'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $link = URL::signedRoute('lavaggio.stock-feed', absolute: false);
        $this->actingAs($admin)->get(route('admin.products'))->assertOk()->assertSee(url($link), false);
        $this->app['auth']->guard()->logout();
        $this->get('/feeds/artikli.xml')->assertForbidden();
        $response = $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertCount(2, $xml->artikl);
        $this->assertSame(['sku', 'naziv', 'ean', 'kolicina', 'tezina_kg', 'mpc'], array_keys((array) $xml->artikl[0]));
        $this->assertSame(['00032', 'Parfem & <cvijeće>', '0123456789012', '0', '0.245', '9.00'], array_values((array) $xml->artikl[0]));
        $this->assertSame('VAR-R', (string) $xml->artikl[1]->sku);
        $this->assertSame('Gel – Ruža', (string) $xml->artikl[1]->naziv);
        $this->assertSame('2', (string) $xml->artikl[1]->kolicina);
        $this->assertSame('18.00', (string) $xml->artikl[1]->mpc);
        $this->assertSame('', (string) $xml->artikl[1]->ean);
        $this->assertSame('', (string) $xml->artikl[1]->tezina_kg);
        $product->update(['stock_qty' => 4, 'base_price' => 4]);
        $this->travel(1)->day();
        $xml = simplexml_load_string($this->get($link)->assertOk()->getContent());
        $this->assertSame('4', (string) $xml->artikl[0]->kolicina);
        $this->assertSame('4.50', (string) $xml->artikl[0]->mpc);
        config(['storefront.home' => 'watchline']);
        $this->get($link)->assertNotFound();
    }
}
