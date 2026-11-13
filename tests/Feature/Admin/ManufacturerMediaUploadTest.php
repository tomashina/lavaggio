<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Media\Manager;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ManufacturerMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_new_brand_can_upload_and_replace_logo_and_banner(): void
    {
        $manufacturer = Manufacturer::create(['code' => 'new-brand', 'is_active' => true]);
        $component = Livewire::actingAs(User::factory()->create())->test(Manager::class, [
            'modelClass' => Manufacturer::class,
            'modelId' => $manufacturer->id,
            'locale' => 'hr',
        ]);

        foreach (['manufacturer_logo', 'manufacturer_banner'] as $collection) {
            $this->assertUploadState($component->html(), $collection, ready: false);

            foreach (['png', 'jpg'] as $extension) {
                $component->set('uploads.'.$collection, UploadedFile::fake()->image('brand.'.$extension, 320, 240));
                $this->assertUploadState($component->html(), $collection, ready: true);

                $component->call('uploadCollection', $collection)->assertHasNoErrors();
                $this->assertUploadState($component->html(), $collection, ready: false);
                $media = $manufacturer->fresh()->getMedia($collection);
                $this->assertCount(1, $media);
                $this->assertFileExists($media->first()->getPath());
                $this->assertSame($extension === 'jpg' ? 'image/jpeg' : 'image/png', $media->first()->mime_type);
            }
        }

        $this->assertDatabaseCount('media', 2);
    }

    public function test_invalid_upload_preserves_existing_logo(): void
    {
        $manufacturer = Manufacturer::create(['code' => 'existing-brand', 'is_active' => true]);
        $logo = $manufacturer->addMedia(UploadedFile::fake()->image('original.png', 100, 100))
            ->toMediaCollection('manufacturer_logo');
        $component = Livewire::actingAs(User::factory()->create())->test(Manager::class, [
            'modelClass' => Manufacturer::class,
            'modelId' => $manufacturer->id,
            'locale' => 'hr',
        ]);

        $component->set('uploads.manufacturer_logo', UploadedFile::fake()->image('large.png')->size(4097))
            ->call('uploadCollection', 'manufacturer_logo')
            ->assertHasErrors(['uploads.manufacturer_logo' => 'max']);
        $component->set('uploads.manufacturer_logo', UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'))
            ->call('uploadCollection', 'manufacturer_logo')
            ->assertHasErrors(['uploads.manufacturer_logo' => 'mimetypes']);

        $this->assertSame($logo->id, $manufacturer->fresh()->getFirstMedia('manufacturer_logo')->id);
        $this->assertFileExists($logo->getPath());
        $this->assertDatabaseCount('media', 1);
    }

    private function assertUploadState(string $html, string $collection, bool $ready): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $input = $xpath->query('//input[@type="file"][@*[name()="wire:model"]="uploads.'.$collection.'"]')->item(0);
        $this->assertNotNull($input);
        $fieldset = $xpath->query('ancestor::fieldset', $input)->item(0);
        $this->assertNotNull($fieldset);
        $this->assertSame('disabled', $fieldset->getAttribute('wire:loading.attr'));
        $this->assertSame('uploads.'.$collection.', uploadCollection', $fieldset->getAttribute('wire:target'));
        $this->assertFalse($fieldset->hasAttribute('disabled'));
        $button = $xpath->query('.//button', $fieldset)->item(0);
        $this->assertNotNull($button);
        // Livewire restores the pre-upload attribute after completion. It must not
        // own the same disabled attribute that changes when the file becomes ready.
        $this->assertFalse($button->hasAttribute('wire:loading.attr'));
        $this->assertSame(! $ready, $button->hasAttribute('disabled'));
    }
}
