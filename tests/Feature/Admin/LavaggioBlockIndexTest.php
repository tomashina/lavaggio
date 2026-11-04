<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Index;
use App\Models\Content\ContentBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioBlockIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr']);
        app()->setLocale('hr');
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $this->actingAs($admin);
    }

    public function test_home_and_footer_widgets_have_one_management_list_with_preview_delete_and_create(): void
    {
        $home = $this->block('home-one', 'lavaggio_products', [['home.content', 'all', 10]]);
        $footer = $this->block('footer-one', 'watchline_benefits', [['footer.benefits', 'all', 10]]);
        $component = Livewire::test(Index::class)->assertViewHas('rows', fn ($rows) => $rows->total() === 0)
            ->assertDontSee('data-lavaggio-other-blocks', false)
            ->assertSeeHtml('wire:click="openPreview('.$home->id.')"')
            ->assertSeeHtml('wire:click="delete('.$home->id.')"')
            ->assertSeeHtml('wire:click="openPreview('.$footer->id.')"')
            ->assertSeeHtml('wire:click="delete('.$footer->id.')"')
            ->assertSee(route('admin.content.blocks.create'), false);
        $this->assertSame(1, substr_count($component->html(), 'href="'.route('admin.content.blocks.edit', $home->id).'"'));
        $home->delete();
        Livewire::test(Index::class)->assertSee('Podnožje — blokovi')->assertSee($footer->name)
            ->assertDontSee('data-lavaggio-other-blocks', false);
    }

    public function test_generic_unplaced_and_other_placement_blocks_remain_accessible(): void
    {
        $this->block('home-only', 'lavaggio_products', [['home.content', 'all', 10]]);
        $generic = $this->block('generic-home', 'banner', [['home.content', 'all', 20]]);
        $other = $this->block('other-page', 'lavaggio_products', [['page.top', 'desktop', 10]]);
        $mixed = $this->block('mixed-home-page', 'lavaggio_products', [['home.content', 'all', 30], ['page.bottom', 'all', 40]]);
        $unplaced = $this->block('unplaced', 'banner');
        Livewire::test(Index::class)->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [$generic->id, $other->id, $mixed->id, $unplaced->id])
            ->assertSee('data-lavaggio-other-blocks', false)->assertSee('page.bottom');
        config(['storefront.home' => 'watchline']);
        Livewire::test(Index::class)->assertViewHas('rows', fn ($rows) => $rows->total() === 5)
            ->assertDontSee('data-lavaggio-other-blocks', false);
    }

    public function test_search_and_surface_filters_apply_to_the_relevant_widget_and_other_placement_lists(): void
    {
        $all = $this->block('common-home', 'lavaggio_products', [['home.content', null, 10]]);
        $all->translations()->update(['title' => 'Posebna njega']);
        $desktop = $this->block('desktop-home', 'lavaggio_campaigns', [['home.content', 'desktop', 20]]);
        $mobile = $this->block('mobile-home', 'lavaggio_problems', [['home.content', 'mobile', 30]]);
        $footer = $this->block('desktop-footer', 'watchline_benefits', [['footer.benefits', 'desktop', 10]]);
        $other = $this->block('generic-mobile', 'banner', [['page.top', 'mobile', 10]]);
        $mixed = $this->block('mixed-devices', 'lavaggio_products', [['home.content', 'mobile', 40], ['page.bottom', 'desktop', 10]]);
        $component = Livewire::test(Index::class)->set('search', 'Posebna njega')
            ->assertViewHas('homeWidgetSlots', fn ($slots) => $slots->pluck('content_block_id')->all() === [$all->id])
            ->assertViewHas('footerWidgetSlots', fn ($slots) => $slots->isEmpty())
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $component->set('search', '')->set('surface', 'desktop')
            ->assertViewHas('homeWidgetSlots', fn ($slots) => $slots->pluck('content_block_id')->all() === [$all->id, $desktop->id])
            ->assertViewHas('footerWidgetSlots', fn ($slots) => $slots->pluck('content_block_id')->all() === [$footer->id])
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$mixed->id]);
        $component->set('surface', 'mobile')
            ->assertViewHas('homeWidgetSlots', fn ($slots) => $slots->pluck('content_block_id')->all() === [$all->id, $mobile->id, $mixed->id])
            ->assertViewHas('footerWidgetSlots', fn ($slots) => $slots->isEmpty())
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$other->id]);
        $component->set('search', 'nema takvog bloka')->assertSee('Nema blokova za odabrane filtre.')
            ->assertDontSee('data-lavaggio-other-blocks', false);
    }

    public function test_moving_a_filtered_widget_uses_the_full_order_and_visibility_stays_editable(): void
    {
        $first = $this->block('hidden-before', 'lavaggio_products', [['home.content', 'all', 10]]);
        $chosen = $this->block('chosen-widget', 'lavaggio_products', [['home.content', 'all', 20]]);
        $last = $this->block('hidden-after', 'lavaggio_products', [['home.content', 'all', 30]]);
        $slot = $chosen->slots()->sole();
        $component = Livewire::test(Index::class)->set('search', 'chosen-widget')
            ->assertViewHas('homeWidgetSlots', fn ($slots) => $slots->count() === 1)
            ->assertViewHas('homeWidgetPositions', fn ($positions) => $positions[$slot->id] === 2)
            ->call('moveHomeWidget', $slot->id, 'up');
        $this->assertSame(10, $slot->fresh()->sort_order);
        $this->assertSame(20, $first->slots()->sole()->sort_order);
        $this->assertSame(30, $last->slots()->sole()->sort_order);
        $component->call('moveHomeWidget', $slot->id, 'down')->call('toggleHomeWidget', $slot->id)
            ->assertViewHas('homeWidgetSlots', fn ($slots) => $slots->count() === 1);
        $this->assertSame(20, $slot->fresh()->sort_order);
        $this->assertFalse($chosen->fresh()->is_active);
        $this->assertFalse($slot->fresh()->is_active);
    }

    public function test_managed_widget_preview_and_delete_actions_still_work(): void
    {
        $block = $this->block('preview-and-delete-widget', 'lavaggio_products', [['home.content', 'all', 10]]);
        $other = $this->block('retained-widget', 'lavaggio_products', [['home.content', 'all', 20]]);
        Livewire::test(Index::class)->call('openPreview', $block->id)
            ->assertSet('previewBlockId', $block->id)->assertViewHas('previewBlock', fn ($preview) => $preview->id === $block->id)
            ->call('closePreview')->assertSet('previewBlockId', null)
            ->call('delete', $block->id);
        $this->assertDatabaseMissing('content_blocks', ['id' => $block->id]);
        $this->assertDatabaseHas('content_blocks', ['id' => $other->id]);
    }

    private function block(string $name, string $type, array $slots = []): ContentBlock
    {
        $block = ContentBlock::create(['code' => $name, 'name' => $name, 'type' => $type, 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => $name]);
        foreach ($slots as [$placement, $variant, $order]) {
            $block->slots()->create(['placement' => $placement, 'frontend_variant' => $variant, 'sort_order' => $order, 'is_active' => true]);
        }

        return $block;
    }
}
