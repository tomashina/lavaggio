<?php

namespace App\Livewire\Admin\Content\Faq;

use App\Models\Content\Support\Faq;
use App\Services\Content\LavaggioFaqGroups;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locale = 'en';

    public string $group = 'all';

    public string $state = 'active';

    public array $faqGroups = [];

    public function mount(): void
    {
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));
        if (config('storefront.home') === 'lavaggio') {
            $this->faqGroups = app(LavaggioFaqGroups::class)->all();
        }
    }

    public function addGroup(): void
    {
        abort_unless(config('storefront.home') === 'lavaggio', 404);
        $this->faqGroups[] = ['code' => 'tema-'.Str::lower(Str::random(8)), 'title' => '', 'sort_order' => count($this->faqGroups) * 10];
    }

    public function removeGroup(int $index): void
    {
        abort_unless(config('storefront.home') === 'lavaggio', 404);
        $code = $this->faqGroups[$index]['code'] ?? '';
        if (Faq::query()->where('group_code', $code)->exists()) {
            $this->addError('faqGroups', 'Tema sadrži pitanja. Prvo im odaberite drugu temu.');

            return;
        }
        unset($this->faqGroups[$index]);
        $this->faqGroups = array_values($this->faqGroups);
    }

    public function saveGroups(): void
    {
        abort_unless(config('storefront.home') === 'lavaggio', 404);
        $this->validate([
            'faqGroups' => ['array', 'max:60'],
            'faqGroups.*.code' => ['required', 'string', 'max:80', 'distinct'],
            'faqGroups.*.title' => ['required', 'string', 'max:160'],
            'faqGroups.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);
        $groups = array_map(fn ($group) => ['code' => trim($group['code']), 'title' => trim($group['title']), 'sort_order' => (int) $group['sort_order']], $this->faqGroups);
        app(SystemSettingsService::class)->put(LavaggioFaqGroups::SETTING, $groups);
        $this->faqGroups = app(LavaggioFaqGroups::class)->all();
        $this->dispatch('notify', type: 'success', message: 'Teme pitanja su spremljene.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function delete(int $faqId): void
    {
        $faq = Faq::query()->find($faqId);
        if (! $faq) {
            $this->dispatch('notify', type: 'danger', message: (string) __('admin.content.faq.manager.notify_not_found'));

            return;
        }

        DB::transaction(function () use ($faq): void {
            $faq->translations()->delete();
            $faq->delete();
        });

        $this->dispatch('notify', type: 'success', message: (string) __('admin.content.faq.manager.notify_deleted'));
        $this->resetPage();
    }

    public function getGroupOptionsProperty(): Collection
    {
        return Faq::query()
            ->select('group_code')
            ->distinct()
            ->orderBy('group_code')
            ->pluck('group_code');
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = Faq::query()
            ->withCount('comments')
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->when($this->group !== 'all', function ($query): void {
                $query->where('group_code', $this->group);
            })
            ->when($this->state === 'active', function ($query): void {
                $query->where('is_active', true);
            })
            ->when($this->state === 'inactive', function ($query): void {
                $query->where('is_active', false);
            })
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('group_code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('question', 'like', '%'.$this->search.'%')
                                ->orWhere('slug', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderBy('group_code')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage);

        return view('livewire.admin.content.faq.manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'faqGroupLabels' => config('storefront.home') === 'lavaggio' ? collect(app(LavaggioFaqGroups::class)->all())->pluck('title', 'code')->all() : [],
        ]);
    }
}
