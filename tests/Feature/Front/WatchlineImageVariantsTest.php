<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Import\WatchlineImageVariantService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\LegacyCatalogImage;
use App\View\Components\Front\Desktop\ProductCard;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchlineImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/image-variants-'.bin2hex(random_bytes(6)));
        File::makeDirectory($this->directory.'/image/catalog', 0755, true);
        config(['storefront.home' => 'watchline', 'legacy_media.enabled' => false,
            'legacy_media.local_root' => $this->directory.'/image', 'legacy_media.variants_enabled' => true,
            'legacy_media.variants_index_root' => $this->directory.'/private', 'legacy_media.origin' => 'https://www.watchline.hr']);
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_planning_is_read_only_and_apply_is_opt_in_without_changing_originals_or_catalog(): void
    {
        $path = $this->image('Žuti sat.png', 1000, 600);
        $hash = hash_file('sha256', $this->original($path));
        $service = app(WatchlineImageVariantService::class);
        $plan = $service->process([$path]);
        $this->assertSame(3, $plan['planned_files']);
        $this->assertSame(0, $plan['generated_files']);
        $this->assertDirectoryDoesNotExist($this->directory.'/private');
        $this->assertDirectoryDoesNotExist($this->directory.'/image/watchline-variants');
        $this->assertSame(asset('image/catalog/'.rawurlencode('Žuti sat.png')), LegacyCatalogImage::url($path, 480));

        $report = $service->process([$path], true);
        $this->assertSame(3, $report['generated_files']);
        $this->assertFalse($report['database_mutated']);
        $this->assertFalse($report['remote_requests']);
        $this->assertSame($hash, hash_file('sha256', $this->original($path)));
        $this->assertDatabaseCount('media', 0);
        $variant = LegacyCatalogImage::variant($path, 480);
        $this->assertSame([480, 288], [$variant['width'], $variant['height']]);
        $this->assertSame($variant['url'], LegacyCatalogImage::url($path, 480));
        $this->assertSame(asset('image/catalog/'.rawurlencode('Žuti sat.png')), LegacyCatalogImage::url($path));
        $this->assertSame(0600, fileperms($this->directory.'/private/index.json') & 0777);
        $this->assertSame(0644, fileperms($this->variantFile($variant)) & 0777);
        config(['legacy_media.variants_enabled' => false]);
        $this->assertNull(LegacyCatalogImage::variant($path, 480));
        $this->assertSame(LegacyCatalogImage::url($path), LegacyCatalogImage::url($path, 480));
        Http::assertNothingSent();
    }

    public function test_small_transparent_sources_never_upscale_or_duplicate_width_descriptors(): void
    {
        $path = $this->image('small.png', 100, 50, true);
        $report = app(WatchlineImageVariantService::class)->process([$path], true);
        $this->assertSame(1, $report['planned_files']);
        $this->assertSame(1, $report['generated_files']);
        $variant = LegacyCatalogImage::responsiveVariant($path);
        $this->assertSame([100, 50], [$variant['width'], $variant['height']]);
        $this->assertSame($variant['url'].' 100w', $variant['srcset']);
        $this->assertSame($variant['url'], $variant['thumb']);
        $image = imagecreatefromwebp($this->variantFile($variant));
        $this->assertGreaterThan(0, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
        imagedestroy($image);
    }

    public function test_selection_is_active_main_and_first_existing_unique_hover_only_and_is_bounded(): void
    {
        $main = $this->image('main.png');
        $hover = $this->image('hover.png');
        $later = $this->image('later.png');
        file_put_contents($this->original('catalog/empty.png'), '');
        $first = $this->product('first', $main, [$main, 'catalog/missing.png', 'catalog/empty.png', $hover, $later]);
        $second = $this->product('second', $later);
        $this->product('inactive', $later)->update(['is_active' => false]);
        $service = app(WatchlineImageVariantService::class);
        $this->assertSame([$main, $hover], $service->select(1)['paths']);
        $this->assertSame([$later], $service->select(0, [$second->id])['paths']);
        $this->assertSame(2, $service->select(0)['products']);
        $this->assertSame([$main, $hover, $later], $service->select(0)['paths']);
        $this->assertSame(1, $service->select(0, [$first->id])['products']);
        $this->assertDirectoryDoesNotExist($this->directory.'/private');
    }

    public function test_repeated_apply_reuses_files_and_stale_or_missing_derivatives_fall_back(): void
    {
        $path = $this->image('change.png');
        $service = app(WatchlineImageVariantService::class);
        $service->process([$path], true);
        $old = LegacyCatalogImage::variant($path, 480);
        $again = $service->process([$path], true);
        $this->assertSame(0, $again['generated_files']);
        $this->assertSame(3, $again['reused_files']);
        $this->assertSame($old, LegacyCatalogImage::variant($path, 480));
        $this->image('change.png', 900, 500);
        touch($this->original($path), time() + 3);
        clearstatcache();
        $this->assertNull(LegacyCatalogImage::variant($path, 480));
        $this->assertSame(LegacyCatalogImage::url($path), LegacyCatalogImage::url($path, 480));
        $service->process([$path], true);
        $new = LegacyCatalogImage::variant($path, 480);
        $this->assertNotSame($old['url'], $new['url']);
        unlink($this->variantFile($new));
        clearstatcache();
        $this->assertNull(LegacyCatalogImage::variant($path, 480));
    }

    public function test_unsafe_sources_symlinks_empty_and_unsupported_images_are_not_encoded(): void
    {
        $path = $this->image('safe.png');
        symlink($this->original($path), $this->original('catalog/link.png'));
        file_put_contents($this->original('catalog/empty.png'), '');
        file_put_contents($this->original('catalog/vector.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $report = app(WatchlineImageVariantService::class)->process([
            'catalog/../catalog/safe.png', 'catalog/link.png', 'catalog/empty.png', 'catalog/vector.svg',
            'https://evil.test/image/'.$path,
        ], true);
        $this->assertSame(0, $report['generated_files']);
        $this->assertSame(5, $report['skipped']['missing_unsafe_unsupported_or_oversized']);
        $this->assertNull(LegacyCatalogImage::variant('https://evil.test/image/'.$path, 480));
        $this->assertNull(LegacyCatalogImage::variant('catalog/link.png', 480));
    }

    public function test_public_cache_symlink_is_rejected_without_writing_its_destination(): void
    {
        $path = $this->image('safe.png');
        File::makeDirectory($this->directory.'/outside');
        symlink($this->directory.'/outside', $this->directory.'/image/watchline-variants');
        try {
            app(WatchlineImageVariantService::class)->process([$path], true);
            $this->fail('Unsafe cache directory was accepted.');
        } catch (\RuntimeException) {
            $this->assertSame([], File::files($this->directory.'/outside'));
        }
    }

    public function test_non_testing_symlink_image_root_cannot_write_to_an_old_shop(): void
    {
        $path = $this->image('safe.png');
        File::makeDirectory($this->directory.'/public');
        symlink($this->directory.'/image', $this->directory.'/public/image');
        $originalPublic = public_path();
        $this->app->usePublicPath($this->directory.'/public');
        $this->app['env'] = 'production';
        config(['legacy_media.local_root' => $this->directory.'/public/image']);
        try {
            $report = app(WatchlineImageVariantService::class)->process([$path], true);
            $this->assertSame(0, $report['generated_files']);
            $this->assertDirectoryDoesNotExist($this->directory.'/image/watchline-variants');
        } finally {
            $this->app->usePublicPath($originalPublic);
            $this->app['env'] = 'testing';
        }
    }

    public function test_corrupt_private_index_is_a_read_only_original_fallback(): void
    {
        $path = $this->image('safe.png');
        app(WatchlineImageVariantService::class)->process([$path], true);
        file_put_contents($this->directory.'/private/index.json', json_encode(['version' => 'v1-q82', 'images' => [$path => 'invalid']]));
        clearstatcache();
        $hash = hash_file('sha256', $this->directory.'/private/index.json');
        $this->assertNull(LegacyCatalogImage::variant($path, 480));
        $this->assertSame($hash, hash_file('sha256', $this->directory.'/private/index.json'));
        $this->assertSame(LegacyCatalogImage::url($path), LegacyCatalogImage::url($path, 480));
        Http::assertNothingSent();
    }

    public function test_exif_rotation_is_applied_before_aspect_ratio_calculation(): void
    {
        $image = imagecreatetruecolor(1000, 600);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $exif = "Exif\0\0II\x2a\0\x08\0\0\0".pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', 6)."\0\0".pack('V', 0);
        file_put_contents($this->original('catalog/rotated.jpg'), substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        app(WatchlineImageVariantService::class)->process(['catalog/rotated.jpg'], true);
        $variant = LegacyCatalogImage::variant('catalog/rotated.jpg', 480);
        $this->assertSame([480, 800], [$variant['width'], $variant['height']]);
    }

    public function test_cards_use_480_and_cart_160_with_real_srcset_and_detail_keeps_original_zoom(): void
    {
        $main = $this->image('main.png', 1000, 600);
        $hover = $this->image('hover.png', 800, 1000);
        $product = $this->product('display', $main, [$hover]);
        app(WatchlineImageVariantService::class)->process([$main, $hover], true);
        $main480 = LegacyCatalogImage::variant($main, 480);
        $main960 = LegacyCatalogImage::variant($main, 960);
        $main160 = LegacyCatalogImage::variant($main, 160);
        $hover480 = LegacyCatalogImage::variant($hover, 480);
        $data = (new ProductCard($product->load('media', 'translations')))->render()->getData();
        $this->assertSame($main480['url'], $data['imageUrl']);
        $this->assertSame($main160['url'], $data['cartImageUrl']);
        $this->assertSame($hover480['url'], $data['hoverImageUrl']);
        $this->assertSame($main480['url'].' 480w, '.$main960['url'].' 960w', $data['imageSrcset']);
        $this->assertSame([480, 288], [$data['imageWidth'], $data['imageHeight']]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Watchline');
        $html = $this->get(route('products.show', ['slug' => 'variant-display']))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $display = $xpath->query('//img[contains(@class,"product-gallery-slide-image")]')->item(0);
        $this->assertSame($main960['url'], $display->getAttribute('src'));
        $this->assertSame('960', $display->getAttribute('width'));
        $this->assertSame('576', $display->getAttribute('height'));
        $this->assertSame($main160['url'], $xpath->query('//*[@data-gallery-thumb]//img')->item(0)->getAttribute('src'));
        $this->assertSame(LegacyCatalogImage::url($main), $xpath->query('//*[@data-gallery-thumb]')->item(0)->getAttribute('data-full'));
        Http::assertNothingSent();
    }

    public function test_other_stores_keep_originals_even_with_prepared_files_and_flag_enabled(): void
    {
        $path = $this->image('safe.png');
        app(WatchlineImageVariantService::class)->process([$path], true);
        config(['storefront.home' => 'libermedia']);
        $this->assertNull(LegacyCatalogImage::responsiveVariant($path));
        $this->assertSame(LegacyCatalogImage::url($path), LegacyCatalogImage::url($path, 480));
    }

    public function test_command_is_dry_run_by_default_and_only_apply_encodes_selected_products(): void
    {
        $path = $this->image('selected.png');
        $product = $this->product('command', $path);
        $this->artisan('watchline:prepare-images', ['--product-ids' => (string) $product->id, '--json' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->directory.'/private');
        $this->artisan('watchline:prepare-images', ['--product-ids' => (string) $product->id, '--apply' => true, '--json' => true])->assertSuccessful();
        $this->assertNotNull(LegacyCatalogImage::variant($path, 480));
        $this->artisan('watchline:prepare-images', ['--limit' => '-1'])->assertFailed();
        $this->assertDatabaseCount('media', 0);
        $this->assertSame($path, $product->fresh()->payload['opencart']['image']);
        Http::assertNothingSent();
    }

    private function image(string $name, int $width = 1000, int $height = 600, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 20, 100, 200, $transparent ? 100 : 0));
        imagepng($image, $this->original('catalog/'.$name));
        imagedestroy($image);
        clearstatcache();

        return 'catalog/'.$name;
    }

    private function original(string $relative): string
    {
        return $this->directory.'/image/'.$relative;
    }

    private function variantFile(array $variant): string
    {
        return $this->directory.'/image/watchline-variants/'.basename($variant['url']);
    }

    private function product(string $code, string $main, array $gallery = []): Product
    {
        $product = Product::query()->create(['code' => 'VARIANT-'.$code, 'is_active' => true, 'base_price' => 10, 'stock_qty' => 3,
            'payload' => ['opencart' => ['image' => $main, 'gallery_images' => $gallery]]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Variant '.$code, 'slug' => 'variant-'.$code]);

        return $product;
    }
}
