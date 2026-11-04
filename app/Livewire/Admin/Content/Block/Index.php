<?php

namespace App\Livewire\Admin\Content\Block;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'contentBlocksPage';

    public string $search = '';

    public string $surface = 'all';

    public ?int $previewBlockId = null;

    public string $locale;

    public function mount(): void
    {
        $this->surface = 'all';
        $this->locale = (string) (request()->query('locale') ?: config('app.locale'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedSurface(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function openPreview(int $id): void
    {
        $exists = ContentBlock::query()->whereKey($id)->exists();
        $this->previewBlockId = $exists ? $id : null;
    }

    public function closePreview(): void
    {
        $this->previewBlockId = null;
    }

    public function delete(int $id): void
    {
        $block = ContentBlock::query()->findOrFail($id);
        $code = (string) $block->code;
        $fixedWidget = (str_starts_with((string) $block->type, 'watchline_') || str_starts_with((string) $block->type, 'lavaggio_'));

        activity('content_blocks')
            ->performedOn($block)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties(['code' => $code])
            ->log('Content block deleted');

        $block->delete();
        if (! $fixedWidget) {
            $this->deleteTemplateFile($code);
        }
        ContentBlockResolver::bumpCacheVersion();

        $this->dispatch('notify', type: 'success', message: __('Content block deleted.'));
    }

    public function moveHomeWidget(int $slotId, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }
        DB::transaction(function () use ($slotId, $direction): void {
            $slots = $this->homeWidgetQuery()->lockForUpdate()->get();
            $position = $slots->search(fn ($slot) => (int) $slot->id === $slotId);
            abort_if($position === false, 404);
            $next = $position + ($direction === 'up' ? -1 : 1);
            if ($next < 0 || $next >= $slots->count()) {
                return;
            }
            $order = $slots->all();
            [$order[$position], $order[$next]] = [$order[$next], $order[$position]];
            foreach ($order as $index => $slot) {
                $slot->update(['sort_order' => ($index + 1) * 10]);
            }
        });
        ContentBlockResolver::bumpCacheVersion();
    }

    public function toggleHomeWidget(int $slotId): void
    {
        $slot = ContentBlockSlot::query()->with('block')
            ->whereIn('placement', ['home.content', 'footer.benefits'])
            ->whereHas('block', fn ($query) => $query->where(fn ($type) => $type->where('type', 'like', 'watchline_%')->orWhere('type', 'like', 'lavaggio_%')))
            ->findOrFail($slotId);
        $active = ! ($slot->is_active && $slot->block->is_active);
        DB::transaction(function () use ($slot, $active): void {
            $slot->block->update(['is_active' => $active]);
            $slot->update(['is_active' => $active]);
        });
        ContentBlockResolver::bumpCacheVersion();
    }

    private function homeWidgetQuery()
    {
        return ContentBlockSlot::query()->where('placement', 'home.content')
            ->whereHas('block', fn ($query) => $query->where(fn ($type) => $type->where('type', 'like', 'watchline_%')->orWhere('type', 'like', 'lavaggio_%')))
            ->orderBy('sort_order')->orderBy('id');
    }

    private function filterWidgetSlots(Builder $query): Builder
    {
        if ($this->search !== '') {
            $query->whereHas('block', fn (Builder $block) => $this->searchBlocks($block));
        }
        if (in_array($this->surface, ['desktop', 'mobile'], true)) {
            $query->where(fn (Builder $slot) => $slot->whereNull('frontend_variant')
                ->orWhere('frontend_variant', 'all')->orWhere('frontend_variant', $this->surface));
        }

        return $query;
    }

    private function searchBlocks(Builder $query): Builder
    {
        return $query->where(function (Builder $block): void {
            $block->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('code', 'like', '%'.$this->search.'%')
                ->orWhere('type', 'like', '%'.$this->search.'%')
                ->orWhereHas('translations', fn (Builder $translation) => $translation->where('title', 'like', '%'.$this->search.'%'));
        });
    }

    private function onlyOtherBlocks(Builder $query): Builder
    {
        return $query->where(function (Builder $block): void {
            $block->where(fn (Builder $type) => $type->where('type', 'not like', 'watchline_%')->where('type', 'not like', 'lavaggio_%'))
                ->orWhereDoesntHave('slots', fn (Builder $slot) => $slot->whereIn('placement', ['home.content', 'footer.benefits']))
                ->orWhereHas('slots', fn (Builder $slot) => $slot->whereNotIn('placement', ['home.content', 'footer.benefits']));
        });
    }

    public function render()
    {
        $lavaggioManager = config('storefront.home') === 'lavaggio';
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = ContentBlock::query()
            ->withCount(['slots', 'items'])
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
                'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ])
            ->when($lavaggioManager, fn (Builder $query) => $this->onlyOtherBlocks($query))
            ->when($this->search !== '', fn (Builder $query) => $this->searchBlocks($query))
            ->when(in_array($this->surface, ['desktop', 'mobile', 'all'], true), function ($query) use ($lavaggioManager): void {
                if ($this->surface === 'all') {
                    return;
                }

                $query->whereHas('slots', function ($slotQuery) use ($lavaggioManager): void {
                    $slotQuery->where(function ($variantQuery): void {
                        $variantQuery->whereNull('frontend_variant')
                            ->orWhere('frontend_variant', 'all')
                            ->orWhere('frontend_variant', $this->surface);
                    });
                    if ($lavaggioManager) {
                        $slotQuery->where(fn (Builder $slot) => $slot->whereNotIn('placement', ['home.content', 'footer.benefits'])
                            ->orWhereHas('block', fn (Builder $type) => $type->where('type', 'not like', 'watchline_%')->where('type', 'not like', 'lavaggio_%')));
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        $allHomeSlots = $this->homeWidgetQuery()->with('block.translations')->get();
        $footerQuery = ContentBlockSlot::query()->where('placement', 'footer.benefits')
            ->whereHas('block', fn ($query) => $query->where(fn ($type) => $type->where('type', 'like', 'watchline_%')->orWhere('type', 'like', 'lavaggio_%')))
            ->with('block.translations')->orderBy('sort_order')->orderBy('id');
        $filterWidgets = $lavaggioManager && ($this->search !== '' || in_array($this->surface, ['desktop', 'mobile'], true));
        $homeWidgetSlots = $filterWidgets
            ? $this->filterWidgetSlots($this->homeWidgetQuery())->with('block.translations')->get()
            : $allHomeSlots;
        $footerWidgetSlots = $filterWidgets ? $this->filterWidgetSlots($footerQuery)->get() : $footerQuery->get();

        $previewBlock = null;
        if ($this->previewBlockId) {
            $previewBlock = ContentBlock::query()
                ->withCount(['slots', 'items'])
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$this->locale, config('app.locale')]),
                    'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ])
                ->find($this->previewBlockId);
        }

        return view('livewire.admin.content.block.index', [
            'rows' => $rows,
            'perPage' => $perPage,
            'previewBlock' => $previewBlock,
            'lavaggioManager' => $lavaggioManager,
            'homeWidgetSlots' => $homeWidgetSlots,
            'footerWidgetSlots' => $footerWidgetSlots,
            'homeWidgetPositions' => $allHomeSlots->values()->mapWithKeys(fn ($slot, $index) => [$slot->id => $index + 1])->all(),
            'homeWidgetTotal' => $allHomeSlots->count(),
        ]);
    }

    private function deleteTemplateFile(string $code): void
    {
        $path = resource_path('views/front/content-blocks/instances/'.$code.'.blade.php');
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
