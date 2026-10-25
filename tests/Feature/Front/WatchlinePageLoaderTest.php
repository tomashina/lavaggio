<?php

namespace Tests\Feature\Front;

use App\Models\Content\Page\InfoPage;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WatchlinePageLoaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Watchline',
            'storefront.theme' => 'herrera',
            'storefront.home' => 'watchline',
            'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => false,
            'commerce.b2c_only' => true,
            'commerce.b2b_only' => false,
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Watchline',
            'store_brand_logo_path' => 'assets/brand/watchline-logo.svg',
            'store_announcement_enabled' => false,
            'store_benefits_bar_enabled' => false,
        ]);

        $page = InfoPage::create(['code' => 'loader-fixture', 'is_active' => true]);
        $page->translations()->create([
            'locale' => 'hr',
            'slug' => 'loader-fixture',
            'title' => 'O trgovini',
            'body_html' => '<p>Podaci o trgovini.</p>',
        ]);
    }

    #[DataProvider('watchlinePages')]
    public function test_watchline_pages_include_one_decorative_loader_and_remain_visible_without_javascript(string $path, string $userAgent): void
    {
        $response = $this->withHeader('User-Agent', $userAgent)->get($path)->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(1, $xpath->query('//head/script[@data-storefront-page-reveal-boot]')->length);
        $this->assertSame(1, $xpath->query('//head/script[@defer and contains(@src, "/front-theme/scripts/storefront-page-reveal.js")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-storefront-page-loader]')->length);
        $this->assertSame(1, $xpath->query('//body/*[1][@data-watchline-page-loader and @data-storefront-page-loader and @aria-hidden="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-watchline-page-loader]//img[contains(@src, "/assets/brand/watchline-logo.svg")]')->length);
        $this->assertSame(3, $xpath->query('//*[@data-watchline-page-loader]//*[contains(concat(" ", normalize-space(@class), " "), " herrera-page-loader-dots ")]/span')->length);
        $this->assertSame(0, $xpath->query('/html[@data-storefront-page-loading or @data-storefront-page-ready]')->length);
        $this->assertSame(1, $xpath->query('//main')->length);
    }

    #[DataProvider('userAgents')]
    public function test_other_libermedia_storefront_keeps_its_existing_no_boot_behavior(string $userAgent): void
    {
        config(['storefront.home' => 'libermedia', 'app.name' => 'Liber Media']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');

        $response = $this->withHeader('User-Agent', $userAgent)->get('/shop')->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(0, $xpath->query('//script[@data-storefront-page-reveal-boot]')->length);
        $this->assertSame(0, $xpath->query('//script[contains(@src, "/front-theme/scripts/storefront-page-reveal.js")]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-watchline-page-loader]')->length);
        $this->assertSame(0, $xpath->query('/html[@data-storefront-page-loading or @data-storefront-page-ready]')->length);
    }

    public static function watchlinePages(): array
    {
        $cases = [];
        foreach (['home' => '/', 'catalog' => '/shop', 'information' => '/page/loader-fixture'] as $page => $path) {
            foreach (self::userAgents() as $device => [$userAgent]) {
                $cases[$page.' '.$device] = [$path, $userAgent];
            }
        }

        return $cases;
    }

    public static function userAgents(): array
    {
        return [
            'desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15'],
            'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
