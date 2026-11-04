<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Page\Manager;
use App\Models\Content\Page\InfoPage;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioPageHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_source_parents_group_children_and_grandchildren_instead_of_database_ids(): void
    {
        $earlierChild = $this->sourcePage(110, 100, 'Praonica', 2);
        $grandchild = $this->sourcePage(111, 110, 'Kako prati rublje', 0);
        $parent = $this->sourcePage(100, 0, 'Usluge', 10, false);
        $laterChild = $this->sourcePage(120, 100, 'Dostava', 2);
        $otherParent = $this->sourcePage(200, 0, 'Cjenik', 20);

        $this->assertNotSame(100, $parent->id);
        $component = $this->manager()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [
                $parent->id, $laterChild->id, $earlierChild->id, $grandchild->id, $otherParent->id,
            ]);

        $context = $component->viewData('pageHierarchy');
        $this->assertSame(0, $context[$parent->id]['depth']);
        $this->assertSame('', $context[$parent->id]['path']);
        $this->assertSame(1, $context[$laterChild->id]['depth']);
        $this->assertSame('Usluge', $context[$laterChild->id]['path']);
        $this->assertSame(2, $context[$grandchild->id]['depth']);
        $this->assertSame('Usluge / Praonica', $context[$grandchild->id]['path']);
    }

    public function test_hierarchy_is_ordered_before_pagination_and_context_survives_on_the_next_page(): void
    {
        app(SystemSettingsService::class)->put('admin_items_per_page', 5);
        $parent = $this->sourcePage(100, 0, 'Usluge', 99);
        $children = [];
        for ($index = 1; $index <= 6; $index++) {
            $children[] = $this->sourcePage(100 + $index, 100, 'Podstranica '.$index, $index);
        }
        $otherParent = $this->sourcePage(200, 0, 'O nama', 100);

        $component = $this->manager()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 8 && $rows->perPage() === 5
                && $rows->pluck('id')->all() === [$parent->id, ...array_map(fn ($page) => $page->id, array_slice($children, 0, 4))]);

        $component->call('gotoPage', 2)
            ->assertViewHas('rows', fn ($rows) => $rows->currentPage() === 2
                && $rows->pluck('id')->all() === [$children[4]->id, $children[5]->id, $otherParent->id]);

        $context = $component->viewData('pageHierarchy');
        $this->assertSame('Usluge', $context[$children[4]->id]['path']);
        $this->assertSame(1, $context[$children[4]->id]['depth']);
        $component->assertSeeText('Usluge');
    }

    public function test_search_keeps_only_matches_with_their_full_parent_context(): void
    {
        $parent = $this->sourcePage(100, 0, 'Usluge', 9);
        $branch = $this->sourcePage(110, 100, 'Praonica', 0);
        $child = $this->sourcePage(111, 110, 'Kako prati rublje', 0);
        $this->sourcePage(120, 100, 'Dostava', 1);
        $child->translations()->create(['locale' => 'en', 'title' => 'Foreign needle', 'slug' => 'foreign-needle']);

        $component = $this->manager()->set('search', 'foreign-needle')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->pluck('id')->all() === [$child->id])
            ->assertSeeText('Kako prati rublje')
            ->assertSeeText('Usluge / Praonica');

        $context = $component->viewData('pageHierarchy');
        $this->assertSame('Usluge / Praonica', $context[$child->id]['path']);
        $this->assertSame(2, $context[$child->id]['depth']);

        $component->set('locale', 'en');
        $context = $component->viewData('pageHierarchy');
        $this->assertSame($parent->code.' / '.$branch->code, $context[$child->id]['path']);
        $component->assertSeeText('Foreign needle');
    }

    public function test_native_orphaned_and_cyclic_pages_remain_visible_exactly_once(): void
    {
        $native = InfoPage::query()->create(['code' => 'native-page', 'layout' => 'default']);
        $orphan = $this->sourcePage(100, 999, 'Bez roditelja');
        $cycleA = $this->sourcePage(200, 201, 'Ciklus A');
        $cycleB = $this->sourcePage(201, 200, 'Ciklus B');
        $selfParent = $this->sourcePage(300, 300, 'Vlastiti roditelj');
        $expected = [$native->id, $orphan->id, $cycleA->id, $cycleB->id, $selfParent->id];
        sort($expected);

        $component = $this->manager();
        $ids = $component->viewData('rows')->pluck('id')->all();
        $this->assertCount(5, $ids);
        sort($ids);
        $this->assertSame($expected, $ids);
        $context = $component->viewData('pageHierarchy');
        foreach ([$native, $orphan, $selfParent] as $page) {
            $this->assertSame(0, $context[$page->id]['depth']);
            $this->assertSame('', $context[$page->id]['path']);
        }
        $this->assertDatabaseCount('content_info_pages', 5);
    }

    public function test_other_storefronts_keep_the_existing_flat_sort_order(): void
    {
        $parent = $this->sourcePage(100, 0, 'Usluge', 9);
        $earlierChild = $this->sourcePage(110, 100, 'Praonica', 0);
        $laterChild = $this->sourcePage(120, 100, 'Dostava', 0);

        foreach (['auto', 'watchline', 'herrera'] as $home) {
            config(['storefront.home' => $home]);
            $this->manager()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [
                $laterChild->id, $earlierChild->id, $parent->id,
            ]);
        }
    }

    private function sourcePage(int $sourceId, int $parentId, string $title, int $sortOrder = 0, bool $includeSourceId = true): InfoPage
    {
        $metadata = ['parent' => $parentId];
        if ($includeSourceId) {
            $metadata['id'] = $sourceId;
        }
        $page = InfoPage::query()->create([
            'code' => 'lavaggio-modx-'.$sourceId,
            'layout' => 'default',
            'is_active' => true,
            'sort_order' => $sortOrder,
            'payload' => ['source' => 'lavaggio-modx', 'modx' => $metadata],
        ]);
        $page->translations()->create(['locale' => 'hr', 'title' => $title, 'slug' => 'stranica-'.$sourceId]);

        return $page;
    }

    private function manager()
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return Livewire::actingAs($admin)->test(Manager::class);
    }
}
