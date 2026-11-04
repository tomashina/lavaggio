<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Content\ContentBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioBlockTypeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr']);
        app()->setLocale('hr');
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $this->actingAs($admin);
    }

    public function test_new_block_dropdown_contains_only_homepage_types_and_starts_as_a_product_collection(): void
    {
        $editor = Livewire::test(Form::class)
            ->assertSet('form.type', 'lavaggio_products')
            ->assertSet('form.slot_placement', 'home.content')
            ->assertSet('form.watchline.source', 'latest');
        $this->assertEqualsCanonicalizing(array_keys(config('lavaggio_widgets')), $this->dropdownTypes($editor->html()));
    }

    #[DataProvider('homepageTypes')]
    public function test_all_supported_homepage_types_can_be_created(string $type): void
    {
        $code = 'allowed-test-'.$type;
        Livewire::test(Form::class)->set('form.type', $type)
            ->set('form.code', $code)->set('form.name', 'Novi blok naslovnice')
            ->call('save')->assertHasNoErrors();
        $block = ContentBlock::query()->where('code', $code)->sole();
        $this->assertSame($type, $block->type);
        $this->assertSame('home.content', $block->slots()->sole()->placement);
    }

    #[DataProvider('forbiddenTypes')]
    public function test_unrelated_types_cannot_be_created_even_by_forging_the_public_type_snapshot(string $type): void
    {
        $editor = Livewire::test(Form::class)
            ->set('types.'.$type, 'Forged option')
            ->set('form.type', $type)
            ->set('form.code', 'forbidden-block')->set('form.name', 'Neželjeni blok');
        $this->assertNotContains($type, $this->dropdownTypes($editor->html()));
        $editor->call('save')->assertHasErrors('form.type');
        $this->assertDatabaseCount('content_blocks', 0);
    }

    public function test_legacy_block_keeps_only_its_own_legacy_type_editable(): void
    {
        $block = ContentBlock::create(['code' => 'legacy-type-restriction-test', 'name' => 'Stari banner', 'type' => 'banner', 'is_active' => true]);
        $path = resource_path('views/front/content-blocks/instances/'.$block->code.'.blade.php');
        $this->assertFileDoesNotExist($path);
        try {
            $editor = Livewire::test(Form::class, ['blockId' => $block->id]);
            $this->assertEqualsCanonicalizing([...array_keys(config('lavaggio_widgets')), 'banner'], $this->dropdownTypes($editor->html()));
            $editor->set('form.name', 'Uređeni stari banner')->call('save')->assertHasNoErrors();
            $this->assertSame('banner', $block->fresh()->type);
            $this->assertSame('Uređeni stari banner', $block->fresh()->name);

            Livewire::test(Form::class, ['blockId' => $block->id])
                ->set('types.rich_text', 'Forged legacy option')->set('form.type', 'rich_text')
                ->call('save')->assertHasErrors('form.type');
            $this->assertSame('banner', $block->fresh()->type);
        } finally {
            File::delete($path);
        }
    }

    public function test_other_storefront_keeps_the_full_type_list_and_generic_creation(): void
    {
        config(['storefront.home' => 'watchline']);
        $editor = Livewire::test(Form::class)->assertSet('form.type', 'banner');
        $this->assertEqualsCanonicalizing(array_keys(config('content_blocks.types')), $this->dropdownTypes($editor->html()));
        $path = resource_path('views/front/content-blocks/instances/other-store-type-restriction-test.blade.php');
        $this->assertFileDoesNotExist($path);
        try {
            $editor->set('form.code', 'other-store-type-restriction-test')->set('form.name', 'Other store banner')
                ->call('save')->assertHasNoErrors();
            $this->assertDatabaseHas('content_blocks', ['code' => 'other-store-type-restriction-test', 'type' => 'banner']);
        } finally {
            File::delete($path);
        }
    }

    public static function homepageTypes(): array
    {
        return array_map(fn ($type) => [$type], ['lavaggio_campaigns', 'lavaggio_products', 'lavaggio_categories', 'lavaggio_brands', 'lavaggio_reviews', 'lavaggio_blog', 'lavaggio_problems']);
    }

    public static function forbiddenTypes(): array
    {
        return array_map(fn ($type) => [$type], ['banner', 'rich_text', 'watchline_products', 'watchline_campaigns', 'banner_static']);
    }

    private function dropdownTypes(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $options = (new \DOMXPath($document))->query('//select[@id="content-block-type"]/option');

        return array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($options));
    }
}
