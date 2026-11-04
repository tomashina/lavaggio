<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioLegalWarrantyFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_links_to_returns_and_discreet_warranty_while_product_and_checkout_keep_the_notice(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront_cache.enabled' => false, 'commerce.b2b_only' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_legal_warranty_enabled' => false,
            'store_legal_warranty_footer_enabled' => false,
            'store_legal_warranty_product_enabled' => false,
            'store_legal_warranty_checkout_enabled' => false,
        ]);
        $product = Product::query()->create(['code' => 'LAV-WARRANTY', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 5]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Lavaggio jamstvo', 'slug' => 'lavaggio-jamstvo']);
        $this->withSession(['front.cart.items' => [$product->id.':0' => ['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 1]]]);
        $asset = asset('assets/legal/legal-guarantee-notice-hr-color.svg');

        $home = $this->get(route('home'))->assertOk();
        $homeXpath = $this->xpath($home->getContent());
        $this->assertSame(0, $homeXpath->query('//*[@data-legal-warranty-notice="footer"]')->count());
        $this->assertSame(1, $homeXpath->query('//*[@data-lavaggio-returns-link]//a[@href="'.route('pages.show', 'povrati-i-reklamacije').'"]')->count());
        $warrantyLinks = $homeXpath->query('//footer//a[@data-legal-warranty-open]');
        $this->assertSame(2, $warrantyLinks->count());
        foreach ($warrantyLinks as $link) {
            $this->assertSame('Zakonsko jamstvo', trim($link->textContent));
            $this->assertSame($asset, $link->getAttribute('href'));
        }

        foreach ([route('products.show', ['slug' => 'lavaggio-jamstvo']) => 'product', route('checkout.create') => 'checkout'] as $url => $position) {
            $response = $this->get($url)->assertOk()->assertSee('Zakonsko jamstvo – najmanje 2 godine');
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//*[@data-legal-warranty-notice="'.$position.'"]//a[@href="'.$asset.'"]')->count(), $position);
            $this->assertSame(1, $xpath->query('//dialog[@id="legal-warranty-modal"]//img[@src="'.$asset.'"]')->count(), $position);
            $this->assertSame(1, $xpath->query('//dialog[@id="legal-warranty-modal"]')->count(), $position);
        }
        $this->assertSame('a22432ff287ae772fd7f1bc7198dce26cbcfba3b1c8c6fa207189c1163e2612e', hash_file('sha256', public_path('assets/legal/legal-guarantee-notice-hr-color.svg')));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
