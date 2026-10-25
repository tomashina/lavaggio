<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\Page\InfoPage;
use App\Services\Import\WatchlineMediaManifestService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\WatchlineMediaReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WatchlineMediaManifestTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private array $exports = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'app.url' => 'https://new.watchline.hr', 'legacy_media.origin' => 'https://www.watchline.hr']);
        Http::preventStrayRequests();
        Http::fake();
        Storage::fake('public');
        config(['filesystems.disks.public.root' => Storage::disk('public')->path('')]);
        $this->directory = storage_path('framework/testing/media-manifest-'.Str::random(10));
        File::ensureDirectoryExists($this->directory.'/catalog');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        foreach ($this->exports as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_manifest_covers_retained_catalog_all_locales_content_settings_and_effective_widgets_without_private_reads_or_writes(): void
    {
        $product = $this->product('catalog/Case Folder/Glavni.JPG', false, ['gallery_images' => [['image' => 'catalog/Gallery one.png'], 'catalog/Gallery two.webp']]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat', 'slug' => 'sat', 'description' => '<img src="/image/catalog/opis.jpg">']);
        $product->translations()->create(['locale' => 'en', 'name' => 'Watch', 'slug' => 'watch', 'description' => '<picture><source srcset="/image/catalog/EN%20one.jpg 1x, /image/catalog/EN%20two.jpg 2x"></picture>']);
        Category::query()->create(['code' => 'inactive-category', 'scope' => 'catalog', 'is_active' => false, 'payload' => ['opencart' => ['image' => 'catalog/category.svg']]]);
        Manufacturer::query()->create(['code' => 'inactive-brand', 'is_active' => false, 'payload' => ['opencart' => ['image' => 'catalog/brand.png']]]);
        $blog = BlogPost::query()->create(['code' => 'old-blog', 'is_active' => false, 'payload' => ['opencart' => ['image' => 'catalog/blog-cover.jpg']]]);
        $blog->translations()->create(['locale' => 'hr', 'title' => 'Blog', 'slug' => 'blog', 'body_html' => '<div style="background-image:url(&quot;/image/catalog/blog-bg.png&quot;)"><img data-src="https://www.watchline.hr/image/catalog/inline.jpg"></div>']);
        $page = InfoPage::query()->create(['code' => 'page', 'is_active' => false]);
        $page->translations()->create(['locale' => 'hr', 'title' => 'Stranica', 'slug' => 'stranica', 'body_html' => '<a href="/image/catalog/page.png">Slika</a>']);
        $block = ContentBlock::query()->create(['code' => 'campaign', 'name' => 'Campaign', 'type' => 'watchline_campaigns', 'is_active' => false, 'payload' => ['watchline' => ['mens_image_url' => '/image/catalog/widget.jpg']]]);
        $block->translations()->create(['locale' => 'hr', 'payload' => ['watchline' => ['cluse_image_1_url' => '/storage/widgets/banner.png']]]);
        app(SystemSettingsService::class)->putMany(['store_brand_logo_path' => 'https://www.watchline.hr/image/brand-logo.svg',
            'store_brand_logo_dark_path' => '/assets/brand/watchline-logo.svg', 'store_brand_logo_optimized_path' => 'store-settings/optimized.webp',
            'store_brand_favicon_ico_path' => 'store-settings/favicon/icon.ico', 'store_og_home_image_path' => 'store-settings/og.jpg',
            'store_newsletter_api_key' => 'SENSITIVE-DO-NOT-EXPORT.jpg']);

        $writes = $privateReads = [];
        DB::listen(function ($query) use (&$writes, &$privateReads): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
            if (preg_match('/\b(?:users|orders|contact_messages|libermedia_import_records|herrera_import_records)\b/i', $query->sql)) {
                $privateReads[] = $query->sql;
            }
        });
        $manifest = app(WatchlineMediaManifestService::class)->inspect();
        $paths = array_column($manifest['opencart_originals'], 'path');
        foreach (['catalog/Case Folder/Glavni.JPG', 'catalog/Gallery one.png', 'catalog/Gallery two.webp', 'catalog/opis.jpg',
            'catalog/EN one.jpg', 'catalog/EN two.jpg', 'catalog/category.svg', 'catalog/brand.png', 'catalog/blog-cover.jpg',
            'catalog/blog-bg.png', 'catalog/inline.jpg', 'catalog/page.png', 'catalog/widget.jpg', 'brand-logo.svg',
            'catalog/CLUSE/cw11502_1_cluse.jpg'] as $path) {
            $this->assertContains($path, $paths);
        }
        $this->assertContains('widgets/banner.png', array_column($manifest['native_public'], 'path'));
        foreach (['store-settings/optimized.webp', 'store-settings/favicon/icon.ico', 'store-settings/og.jpg'] as $path) {
            $this->assertContains($path, array_column($manifest['native_public'], 'path'));
        }
        $this->assertContains('assets/brand/watchline-logo.svg', array_column($manifest['git_public_assets'], 'path'));
        $this->assertSame(1, $manifest['scanned_rows']['products']);
        $this->assertFalse($manifest['source_root_verified']);
        $this->assertSame([], $writes);
        $this->assertSame([], $privateReads);
        $this->assertStringNotContainsString('SENSITIVE-DO-NOT-EXPORT', json_encode($manifest));
        Http::assertNothingSent();
    }

    public function test_source_verification_preserves_exact_case_spaces_and_rejects_missing_empty_and_symlink_files_from_transfer_list(): void
    {
        file_put_contents($this->directory.'/catalog/Photo A.JPG', 'original-image');
        file_put_contents($this->directory.'/catalog/empty.jpg', '');
        file_put_contents($this->directory.'/outside.jpg', 'outside');
        symlink($this->directory.'/outside.jpg', $this->directory.'/catalog/link.jpg');
        $this->product('catalog/Photo A.JPG', true, ['gallery_images' => ['catalog/Photo A.JPG', 'catalog/photo a.JPG', 'catalog/empty.jpg', 'catalog/link.jpg', 'catalog/missing.jpg']]);

        $service = app(WatchlineMediaManifestService::class);
        $manifest = $service->inspect($this->directory);
        $this->assertTrue($manifest['source_root_verified']);
        $this->assertSame(5, $manifest['summary']['opencart']['paths']);
        $this->assertSame(1, $manifest['summary']['opencart']['verified']);
        $this->assertSame(strlen('original-image'), $manifest['summary']['opencart']['verified_bytes']);
        $this->assertSame("catalog/Photo A.JPG\0", $service->fileList($manifest));
        $this->assertSame('symlink_rejected', collect($manifest['opencart_originals'])->firstWhere('path', 'catalog/link.jpg')['state']);
    }

    public function test_only_originals_are_selected_from_exact_opencart_cache_resize_paths_and_aliases_are_explicit(): void
    {
        $this->product('catalog/actual-200x200.jpg', true, ['gallery_images' => ['https://www.watchline.hr/image/cache/catalog/actual-200x200-800x800.jpg', '/image/cache/catalog/Cache%20Only-100x250.png']]);
        $service = app(WatchlineMediaManifestService::class);
        $manifest = $service->inspect();
        $this->assertSame(['catalog/Cache Only.png', 'catalog/actual-200x200.jpg'], array_column($manifest['opencart_originals'], 'path'));
        $this->assertCount(2, $manifest['cache_aliases']);
        $this->assertTrue($manifest['cache_aliases'][0]['new_shop_url_update_required']);
        $this->assertStringNotContainsString('cache/', $service->fileList($manifest));
        $this->assertSame("catalog/Cache Only.png\0catalog/actual-200x200.jpg\0", $service->fileList($manifest));
    }

    public function test_unrelated_external_urls_and_invalid_references_are_reported_without_tokens_or_raw_html(): void
    {
        $product = $this->product('catalog/valid.jpg', true, ['gallery_images' => ['../PRIVATE-MARKER.jpg', 'catalog/%252e%252e/secret.jpg', "catalog/injected\nname.jpg"]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat', 'slug' => 'sat', 'description' => '<img src="https://unrelated.example/image.jpg?token=PRIVATE-MARKER"><img src="javascript:PRIVATE-MARKER.jpg"><script src="https://scripts.example/app.js"></script>']);
        $manifest = app(WatchlineMediaManifestService::class)->inspect();
        $this->assertSame(['catalog/valid.jpg'], array_column($manifest['opencart_originals'], 'path'));
        $this->assertContains('unrelated_external', array_column($manifest['excluded_or_rejected'], 'reason'));
        $this->assertContains('unsupported_scheme', array_column($manifest['excluded_or_rejected'], 'reason'));
        $this->assertStringNotContainsString('PRIVATE-MARKER', json_encode($manifest));
        $this->assertStringNotContainsString('scripts.example', json_encode($manifest));
        Http::assertNothingSent();
    }

    public function test_native_public_images_and_local_derivatives_have_a_separate_root_and_private_media_are_not_read(): void
    {
        $product = $this->product('catalog/old.jpg');
        $id = DB::table('media')->insertGetId(['model_type' => Product::class, 'model_id' => $product->id, 'collection_name' => 'product_main',
            'name' => 'Native', 'file_name' => 'Native One.JPG', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'conversions_disk' => 'public', 'size' => 5,
            'manipulations' => '{}', 'custom_properties' => '{}', 'generated_conversions' => '{"thumb_100x100_webp":true}',
            'responsive_images' => '{"media_library_original":{"urls":["Native One___100_100.jpg"]}}']);
        Storage::disk('public')->put($id.'/Native One.JPG', 'image');
        Storage::disk('public')->put($id.'/conversions/Native One-thumb_100x100_webp.webp', 'thumb');
        Storage::disk('public')->put($id.'/conversions/unreferenced-old-file.webp', 'orphan');
        Storage::disk('public')->put($id.'/responsive-images/Native One___100_100.jpg', 'responsive');
        DB::table('media')->insert(['model_type' => 'PrivateCustomerAttachment', 'model_id' => 1, 'collection_name' => 'identity',
            'name' => 'PRIVATE-MARKER', 'file_name' => 'PRIVATE-MARKER.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'size' => 5,
            'manipulations' => '{}', 'custom_properties' => '{}', 'generated_conversions' => '{}', 'responsive_images' => '{}']);
        DB::table('media')->insert(['model_type' => Product::class, 'model_id' => 999999, 'collection_name' => 'product_main',
            'name' => 'Orphan', 'file_name' => 'ORPHAN-MARKER.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'size' => 5,
            'manipulations' => '{}', 'custom_properties' => '{}', 'generated_conversions' => '{}', 'responsive_images' => '{}']);

        $service = app(WatchlineMediaManifestService::class);
        $manifest = $service->inspect();
        $paths = array_column($manifest['native_public'], 'path');
        $this->assertSame([$id.'/Native One.JPG', $id.'/conversions/Native One-thumb_100x100_webp.webp', $id.'/responsive-images/Native One___100_100.jpg'], $paths);
        $this->assertSame(3, $manifest['summary']['native_public']['verified']);
        $this->assertSame(1, $manifest['scanned_rows']['public_model_native_images']);
        $this->assertStringNotContainsString('PRIVATE-MARKER', json_encode($manifest));
        $this->assertStringNotContainsString('ORPHAN-MARKER', json_encode($manifest));
        $this->assertSame(1, $manifest['scanned_rows']['orphan_native_images_skipped']);
        $this->assertStringNotContainsString('catalog/', $service->fileList($manifest, 'native_public'));
        Http::assertNothingSent();
    }

    public function test_command_writes_only_new_ignored_private_metadata_files_and_never_overwrites_or_exports_to_public(): void
    {
        $this->product('catalog/Space Name.jpg');
        $relative = '.local/watchline-media/test-'.Str::random(12);
        $this->exports[] = base_path($relative);
        $this->artisan('watchline:media-manifest', ['--output' => $relative, '--json' => true])->assertSuccessful();
        $this->assertSame(0700, fileperms(base_path($relative)) & 0777);
        foreach (['manifest.json', 'opencart-originals.files0', 'native-public.files0'] as $filename) {
            $this->assertFileExists(base_path($relative.'/'.$filename));
            $this->assertSame(0600, fileperms(base_path($relative.'/'.$filename)) & 0777);
        }
        $this->assertSame("catalog/Space Name.jpg\0", file_get_contents(base_path($relative.'/opencart-originals.files0')));
        $before = hash_file('sha256', base_path($relative.'/manifest.json'));
        $this->artisan('watchline:media-manifest', ['--output' => $relative])->assertFailed();
        $this->assertSame($before, hash_file('sha256', base_path($relative.'/manifest.json')));
        $this->artisan('watchline:media-manifest', ['--output' => 'public/manifest-export'])->assertFailed();
        $this->assertDirectoryDoesNotExist(public_path('manifest-export'));
        Http::assertNothingSent();
    }

    public function test_command_rejects_output_symlink_and_missing_source_root_without_writing_output(): void
    {
        File::ensureDirectoryExists(base_path('.local/watchline-media'));
        $relative = '.local/watchline-media/link-'.Str::random(12);
        symlink($this->directory, base_path($relative));
        try {
            $this->artisan('watchline:media-manifest', ['--output' => $relative.'/escape'])->assertFailed();
            $this->assertDirectoryDoesNotExist($this->directory.'/escape');
        } finally {
            unlink(base_path($relative));
        }
        $output = '.local/watchline-media/missing-source-'.Str::random(12);
        $this->artisan('watchline:media-manifest', ['--output' => $output, '--source-root' => $this->directory.'/absent'])->assertFailed();
        $this->assertDirectoryDoesNotExist(base_path($output));
    }

    public function test_normalizer_handles_image_url_entities_queries_and_native_paths_without_decoding_twice(): void
    {
        $this->assertSame(['kind' => 'opencart', 'path' => 'catalog/Exact Case/A B.JPG'], WatchlineMediaReference::classify('https://www.watchline.hr/image/catalog/Exact%20Case/A%20B.JPG?v=1&amp;size=2'));
        $this->assertSame(['kind' => 'native_public', 'path' => 'widgets/New Image.png'], WatchlineMediaReference::classify('https://new.watchline.hr/storage/widgets/New%20Image.png'));
        $this->assertSame(['kind' => 'opencart', 'path' => 'logo.ico'], WatchlineMediaReference::classify('/image/logo.ico'));
        $this->assertSame('rejected', WatchlineMediaReference::classify('/image/catalog/%252e%252e/a.jpg')['kind']);
    }

    #[DataProvider('unsafeReferences')]
    public function test_path_validation_prevents_traversal_absolute_filesystem_and_encoded_list_injection(string $reference): void
    {
        $result = WatchlineMediaReference::classify($reference, true);
        $this->assertNotSame('opencart', $result['kind']);
        $this->assertArrayNotHasKey('path', $result);
    }

    public static function unsafeReferences(): array
    {
        return array_map(fn ($value) => [$value], ['../a.jpg', '/home/watchline/private.jpg', 'catalog/../a.jpg', 'catalog/%2e%2e/a.jpg',
            'catalog/%252e%252e/a.jpg', "catalog/a\0.jpg", "catalog/a\n.jpg", 'catalog\\outside.jpg',
            'https://user:pass@www.watchline.hr/image/catalog/a.jpg', 'file:///image/catalog/a.jpg', '/image/cache/catalog/not-resized.jpg']);
    }

    private function product(string $image, bool $active = true, array $extra = []): Product
    {
        $code = 'MANIFEST-'.Str::random(12);

        return Product::query()->create(['code' => $code, 'sku' => $code, 'is_active' => $active, 'base_price' => 10, 'stock_qty' => 1,
            'payload' => ['opencart' => array_merge(['image' => $image], $extra)]]);
    }
}
