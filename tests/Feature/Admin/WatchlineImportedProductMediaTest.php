<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Manager as ProductManager;
use App\Livewire\Admin\Media\Manager as MediaManager;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Support\Media\LegacyCatalogImage;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WatchlineImportedProductMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.home' => 'watchline', 'legacy_media.enabled' => true,
            'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
        Storage::fake('public');
        Storage::fake('legacy-preview');
        config(['legacy_media.local_root' => Storage::disk('legacy-preview')->path('')]);
        Http::preventStrayRequests();
    }

    public function test_imported_photos_are_ordered_read_only_previews_without_creating_native_media(): void
    {
        $product = $this->product();
        $payload = $product->getRawOriginal('payload');
        Storage::disk('legacy-preview')->put('catalog/watches/ES4649.jpg', 'existing-local-original');
        $main = asset('image/catalog/watches/ES4649.jpg');
        $component = $this->manager($product)
            ->assertSee('Fotografije iz stare trgovine')
            ->assertSee('Uvezeno · pregled')
            ->assertSeeInOrder([$main, 'https://www.watchline.hr/image/catalog/watches/early.jpg', 'https://www.watchline.hr/image/catalog/watches/late.jpg'], false)
            ->assertDontSee('unsafe.example');

        $dom = new DOMDocument;
        @$dom->loadHTML($component->html());
        $xpath = new DOMXPath($dom);
        $this->assertSame(3, $xpath->query('//*[@data-admin-imported-product-images]//img')->count());
        $this->assertSame(0, $xpath->query('//*[@data-admin-imported-product-images]//button | //*[@data-admin-imported-product-images]//input')->count());
        $this->assertSame($payload, $product->fresh()->getRawOriginal('payload'));
        $this->assertDatabaseCount('media', 0);
        Http::assertNothingSent();
    }

    public function test_native_upload_metadata_and_delete_work_without_changing_imported_photos(): void
    {
        $product = $this->product();
        $payload = $product->getRawOriginal('payload');
        $component = $this->manager($product)
            ->set('uploads.product_main', UploadedFile::fake()->image('new-watch.jpg', 240, 240))
            ->call('uploadCollection', 'product_main')
            ->assertHasNoErrors()
            ->assertDontSee('data-admin-imported-product-images', false);

        $media = $product->fresh()->getFirstMedia('product_main');
        $this->assertNotNull($media);
        $this->assertFileExists($media->getPath());
        $component->set('meta.'.$media->id.'.alt', 'Nova fotografija sata')
            ->call('saveMeta', $media->id)->assertHasNoErrors();
        $this->assertSame('Nova fotografija sata', $media->fresh()->getCustomProperty('alt.hr'));
        $this->assertSame($payload, $product->fresh()->getRawOriginal('payload'));

        $component->call('delete', $media->id)
            ->assertSee('data-admin-imported-product-images', false)
            ->assertSee('https://www.watchline.hr/image/catalog/watches/ES4649.jpg', false);
        $this->assertDatabaseCount('media', 0);
        $this->assertSame($payload, $product->fresh()->getRawOriginal('payload'));
        Http::assertNothingSent();
    }

    public function test_watchline_manager_only_accepts_photos_and_preserves_hidden_existing_documents(): void
    {
        $product = $this->product();
        $document = $product->addMedia(UploadedFile::fake()->createWithContent('existing.pdf', '%PDF-1.4 fixture'))
            ->toMediaCollection('product_documents');
        $component = $this->manager($product)
            ->assertViewHas('collections', fn ($collections) => array_keys($collections) === ['product_main', 'product_gallery'])
            ->assertDontSee('uploads.product_documents', false)
            ->assertDontSee('uploads.product_energy_label', false)
            ->assertDontSee('uploads.product_information_sheet', false)
            ->set('uploads.product_documents', [UploadedFile::fake()->createWithContent('blocked.pdf', '%PDF-1.4 fixture')])
            ->call('uploadCollection', 'product_documents')
            ->call('delete', $document->id)
            ->call('copyToMain', $document->id);

        $this->assertDatabaseHas('media', ['id' => $document->id, 'collection_name' => 'product_documents']);
        $this->assertDatabaseCount('media', 1);
        $this->assertFileExists($document->getPath());
        $this->assertNull($product->fresh()->getFirstMedia('product_main'));
        $component->assertSee('data-admin-imported-product-images', false);
    }

    public function test_watchline_list_uses_the_same_native_first_thumbnail_and_other_stores_keep_their_collections(): void
    {
        $product = $this->product();
        Livewire::actingAs(User::factory()->create())->test(ProductManager::class)
            ->set('locale', 'hr')->set('search', 'ES4649')
            ->assertSee(LegacyCatalogImage::first($product), false);

        $media = $product->addMedia(UploadedFile::fake()->image('native-thumbnail.jpg', 240, 240))->toMediaCollection('product_main');
        Livewire::actingAs(User::factory()->create())->test(ProductManager::class)
            ->set('locale', 'hr')->set('search', 'ES4649')
            ->assertSee('native-thumbnail', false)
            ->assertDontSee('https://www.watchline.hr/image/catalog/watches/ES4649.jpg', false);

        config(['storefront.home' => 'auto']);
        $this->manager($product)
            ->assertViewHas('collections', fn ($collections) => isset($collections['product_documents'], $collections['product_energy_label'], $collections['product_information_sheet']))
            ->assertDontSee('data-admin-imported-product-images', false);
    }

    private function manager(Product $product)
    {
        return Livewire::actingAs(User::factory()->create())->test(MediaManager::class, [
            'modelClass' => Product::class, 'modelId' => $product->id, 'locale' => 'hr',
        ]);
    }

    private function product(): Product
    {
        $product = Product::create([
            'code' => 'imported-ES4649', 'sku' => 'ES4649', 'is_active' => true, 'base_price' => 99,
            'payload' => ['opencart' => [
                'model' => 'ES4649', 'image' => 'catalog/watches/ES4649.jpg',
                'gallery_images' => [
                    ['image' => 'catalog/watches/late.jpg', 'sort_order' => 20],
                    ['image' => 'catalog/watches/early.jpg', 'sort_order' => 10],
                    ['image' => 'catalog/watches/ES4649.jpg', 'sort_order' => 30],
                    ['image' => 'https://unsafe.example/private.jpg', 'sort_order' => 40],
                ],
            ]],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'FOSSIL ES4649', 'slug' => 'fossil-es4649']);

        return $product;
    }
}
