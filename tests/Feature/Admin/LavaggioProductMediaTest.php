<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Media\Manager as MediaManager;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LavaggioProductMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio']);
        Storage::fake('public');
    }

    public function test_lavaggio_product_photos_and_documents_remain_usable_without_energy_upload_controls(): void
    {
        $product = $this->product();
        $component = $this->manager($product)
            ->assertViewHas('collections', fn ($collections) => array_keys($collections) === ['product_main', 'product_gallery', 'product_documents'])
            ->assertDontSee('Službena energetska oznaka')
            ->assertDontSee('Informacijski list proizvoda (PIS)')
            ->assertDontSee('uploads.product_energy_label', false)
            ->assertDontSee('uploads.product_information_sheet', false)
            ->set('uploads.product_main', UploadedFile::fake()->image('detergent.jpg', 240, 240))
            ->call('uploadCollection', 'product_main')->assertHasNoErrors()
            ->set('uploads.product_gallery', [UploadedFile::fake()->image('detergent-back.jpg', 240, 240)])
            ->call('uploadCollection', 'product_gallery')->assertHasNoErrors()
            ->set('uploads.product_documents', [UploadedFile::fake()->createWithContent('instructions.pdf', '%PDF-1.4 fixture')])
            ->call('uploadCollection', 'product_documents')->assertHasNoErrors();

        foreach (['product_main', 'product_gallery', 'product_documents'] as $collection) {
            $media = $product->fresh()->getFirstMedia($collection);
            $this->assertNotNull($media);
            $this->assertFileExists($media->getPath());
        }
        $main = $product->fresh()->getFirstMedia('product_main');
        $component->set('meta.'.$main->id.'.alt', 'Prednja strana deterdženta')
            ->call('saveMeta', $main->id)->assertHasNoErrors();
        $this->assertSame('Prednja strana deterdženta', $main->fresh()->getCustomProperty('alt.hr'));
    }

    public function test_hidden_energy_files_are_preserved_and_cannot_be_modified_through_the_lavaggio_media_editor(): void
    {
        $product = $this->product();
        $existing = [];
        foreach (['product_energy_label', 'product_information_sheet'] as $collection) {
            $media = $product->addMedia(UploadedFile::fake()->createWithContent($collection.'.pdf', '%PDF-1.4 retained fixture'))
                ->withCustomProperties(['alt' => ['hr' => 'Sačuvani opis']])->toMediaCollection($collection);
            $existing[] = [$media, $media->fresh()->getRawOriginal()];
        }
        $component = $this->manager($product);

        foreach ($existing as [$media, $before]) {
            $component->set('uploads.'.$media->collection_name, UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 replacement'))
                ->call('uploadCollection', $media->collection_name)
                ->set('meta.'.$media->id.'.name', 'Neželjena promjena')
                ->set('meta.'.$media->id.'.alt', 'Neželjeni opis')
                ->call('saveMeta', $media->id)
                ->call('copyToMain', $media->id)
                ->call('delete', $media->id);
            $this->assertSame($before, $media->fresh()->getRawOriginal());
            $this->assertFileExists($media->getPath());
        }
        $this->assertDatabaseCount('media', 2);
        $this->assertNull($product->fresh()->getFirstMedia('product_main'));
    }

    public function test_other_storefronts_retain_energy_media_controls(): void
    {
        config(['storefront.home' => 'auto']);
        $this->manager($this->product())
            ->assertSee('Službena energetska oznaka')
            ->assertSee('Informacijski list proizvoda (PIS)')
            ->assertSee('uploads.product_energy_label', false)
            ->assertSee('uploads.product_information_sheet', false);
    }

    private function manager(Product $product)
    {
        return Livewire::actingAs(User::factory()->create())->test(MediaManager::class, [
            'modelClass' => Product::class, 'modelId' => $product->id, 'locale' => 'hr',
        ]);
    }

    private function product(): Product
    {
        return Product::create(['code' => 'lavaggio-media', 'sku' => 'LAV-MEDIA', 'is_active' => true, 'base_price' => 10]);
    }
}
