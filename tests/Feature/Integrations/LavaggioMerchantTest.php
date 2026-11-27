<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Services\Front\StoreSettingsService;
use App\Services\Integrations\Google\CustomerReviews;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioMerchantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.local_safe_mode' => false,
            'app.locale' => 'hr', 'app.url' => 'https://www.lavaggio.hr', 'legacy_media.enabled' => true,
            'legacy_media.origin' => 'https://images.example.test']);
        app()->setLocale('hr');
    }

    public function test_settings_save_the_pixel_and_merchant_separately_and_validate_the_ids(): void
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);
        $form = Livewire::actingAs($user)->test(StoreSettings::class);
        $form->set('tab', 'integrations')->assertSee('Meta Pixel ID')
            ->set('form.lavaggio_meta_pixel_id', '<script>')->call('save')->assertHasErrors('form.lavaggio_meta_pixel_id')
            ->set('form.lavaggio_meta_pixel_id', '879053914431904')->call('save')->assertHasNoErrors();
        $this->assertSame('879053914431904', app(StoreSettingsService::class)->analytics()['meta_pixel_id']);
        $form->set('tab', 'merchant')->assertSee('feeds/google-merchant.xml')
            ->set('form.lavaggio_merchant_reviews_enabled', true)->call('save')->assertHasErrors('form.lavaggio_merchant_id')
            ->set('form.lavaggio_merchant_id', '5918562959')->set('form.lavaggio_merchant_feed_enabled', true)
            ->call('save')->assertHasNoErrors();
        $settings = app(SystemSettingsService::class);
        $this->assertSame('5918562959', $settings->get('lavaggio_merchant_id'));
        $this->assertTrue($settings->get('lavaggio_merchant_feed_enabled'));
        $settings->put('store_cookie_consent_enabled', false);
        $this->assertSame('', app(StoreSettingsService::class)->analytics()['meta_pixel_id']);
    }

    public function test_merchant_xml_uses_live_public_prices_availability_images_and_valid_identifiers(): void
    {
        $path = '/feeds/google-merchant.xml';
        $this->get($path)->assertNotFound();
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['lavaggio_merchant_feed_enabled' => true, 'store_pricing_prices_include_tax' => true]);
        foreach (['gel', 'rasprodan', 'skriven', 'bez-slike'] as $code) {
            $product = Product::create(['code' => $code, 'sku' => $code, 'base_price' => 10, 'stock_qty' => $code === 'rasprodan' ? 0 : 5,
                'is_active' => $code !== 'skriven', 'barcode' => match ($code) { 'gel' => '4006381333931', 'rasprodan' => '123', default => null },
                'weight_kg' => $code === 'gel' ? 1.245 : null, 'content_quantity' => 1, 'content_unit' => 'l',
                'payload' => $code === 'bez-slike' ? [] : ['opencart' => ['image' => 'catalog/test.jpg']]]);
            $product->translations()->create(['locale' => 'hr', 'slug' => $code, 'name' => $code.' & pranje', 'description' => '<p>Gel &amp; sapun</p>']);
        }
        CatalogAction::create(['code' => 'akcija', 'title' => 'Akcija', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_ALL, 'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 20, 'is_active' => true]);
        $xml = simplexml_load_string($this->get($path)->assertOk()->streamedContent());
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertCount(2, $xml->channel->item);
        $offer = $xml->channel->item[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame('gel & pranje', (string) $offer->title);
        $this->assertSame('Gel & sapun', (string) $offer->description);
        $this->assertSame('10.00 EUR', (string) $offer->price);
        $this->assertSame('8.00 EUR', (string) $offer->sale_price);
        $this->assertSame('in_stock', (string) $offer->availability);
        $this->assertSame('1.245 kg', (string) $offer->shipping_weight);
        $this->assertSame('1l', (string) $offer->unit_pricing_measure);
        $this->assertSame('4006381333931', (string) $offer->gtin);
        $this->assertSame('https://www.lavaggio.hr/proizvod/gel', (string) $offer->link);
        $this->assertStringContainsString('catalog/test.jpg', (string) $offer->image_link);
        $out = $xml->channel->item[1]->children('http://base.google.com/ns/1.0');
        $this->assertSame('out_of_stock', (string) $out->availability);
        $this->assertFalse(isset($out->shipping_weight));
        $this->assertFalse(isset($out->gtin));
        $settings->put('lavaggio_merchant_include_unavailable', false);
        $this->assertCount(1, simplexml_load_string($this->get($path)->assertOk()->streamedContent())->channel->item);
        config(['storefront.home' => 'watchline']);
        $this->get($path)->assertNotFound();
    }

    public function test_variants_link_to_the_selected_size_and_use_its_price_stock_and_gtin(): void
    {
        app(SystemSettingsService::class)->putMany(['lavaggio_merchant_feed_enabled' => true, 'store_pricing_prices_include_tax' => true]);
        $product = Product::create(['code' => 'SIZE', 'sku' => 'PARENT', 'base_price' => 10, 'stock_qty' => 0,
            'is_active' => true, 'weight_kg' => 0.75, 'barcode' => '4006381333931', 'payload' => ['opencart' => ['image' => 'catalog/test.jpg']]]);
        $product->translations()->create(['locale' => 'hr', 'slug' => 'rukavice', 'name' => 'Rukavice']);
        $option = Option::create(['code' => 'velicina', 'type' => 'select', 'is_active' => true]);
        $value = $option->values()->create(['code' => 'm', 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'slug' => 'm', 'name' => 'M']);
        $variant = $product->optionValues()->create(['option_value_id' => $value->id, 'mode' => 'single', 'sku' => 'SIZE-M',
            'stock_qty' => 2, 'price_override' => 13, 'combination_hash' => 'size-m', 'is_active' => true,
            'payload' => ['barcode' => '036000291452', 'weight_kg' => 0.325]]);
        $xml = simplexml_load_string($this->get('/feeds/google-merchant.xml')->assertOk()->streamedContent());
        $this->assertCount(1, $xml->channel->item);
        $offer = $xml->channel->item[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame('SIZE-M', (string) $offer->id);
        $this->assertSame('PARENT', (string) $offer->item_group_id);
        $this->assertSame('M', (string) $offer->size);
        $this->assertSame('0.325 kg', (string) $offer->shipping_weight);
        $this->assertSame('036000291452', (string) $offer->gtin);
        $this->assertSame('13.00 EUR', (string) $offer->price);
        $this->assertSame('in_stock', (string) $offer->availability);
        $this->assertSame('https://www.lavaggio.hr/proizvod/rukavice?product_option_value_id='.$variant->id, (string) $offer->link);
        $variant->update(['payload' => ['weight_kg' => 0]]);
        $getOffer = fn () => simplexml_load_string($this->get('/feeds/google-merchant.xml')->assertOk()->streamedContent())
            ->channel->item[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame('0.750 kg', (string) $getOffer()->shipping_weight);
        $product->update(['weight_kg' => null]);
        $product->packages()->create(['code' => 'paket', 'name' => 'Paket', 'is_default' => true, 'is_active' => true, 'weight_kg' => 0.654]);
        $this->assertSame('0.654 kg', (string) $getOffer()->shipping_weight);
        $product->update(['weight_kg' => 1000.001]);
        $this->assertFalse(isset($getOffer()->shipping_weight));
    }

    public function test_customer_reviews_use_the_confirmed_receipt_and_are_independent_of_analytics(): void
    {
        $this->app['env'] = 'production';
        app(SystemSettingsService::class)->putMany(['lavaggio_merchant_reviews_enabled' => true, 'lavaggio_merchant_id' => '5918562959']);
        $order = Order::create(['order_number' => 'LAV-TEST-REVIEW', 'currency_code' => 'EUR', 'currency_rate' => 1,
            'locale' => 'hr', 'source' => 'web', 'customer_name' => 'Test', 'customer_email' => 'fixture@example.test',
            'shipping_country_code' => 'HR', 'payment_method_code' => 'cod', 'placed_at' => '2026-11-27 10:00:00']);
        $order->items()->create(['name' => 'Prašak', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
            'payload' => ['gtin' => '4006381333931', 'delivery_mode' => 'supplier']]);
        $expected = ['merchant_id' => 5918562959, 'order_id' => $order->order_number, 'email' => 'fixture@example.test',
            'delivery_country' => 'HR', 'estimated_delivery_date' => '2026-12-11', 'products' => [['gtin' => '4006381333931']]];
        $this->assertSame($expected, app(CustomerReviews::class)->payload($order));
        $path = route('checkout.success', $order->order_number);
        $this->get($path)->assertNotFound();
        $this->withSession(['front.checkout.last_order_id' => $order->id])->get($path)->assertOk()
            ->assertSee(Js::from($expected)->toHtml(), false)->assertSee('gapi.surveyoptin.render', false);
        $order->update(['payment_method_code' => 'corvus']);
        $this->get($path)->assertOk()->assertDontSee('gapi.surveyoptin.render', false);
    }
}
