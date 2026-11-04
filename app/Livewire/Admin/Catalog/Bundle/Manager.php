<?php

namespace App\Livewire\Admin\Catalog\Bundle;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $productSearch = '';

    public string $title = '';

    public bool $isActive = false;

    public ?string $startsAt = null;

    public ?string $endsAt = null;

    public int $sortOrder = 0;

    public array $products = [];

    public array $discounts = [];

    private function permit(string $ability): void
    {
        abort_unless($this->can($ability), 403);
    }

    private function can(string $ability): bool
    {
        $user = auth()->user();

        return $user && ($user->isA('superadmin') || $user->can('catalog.actions.'.$ability));
    }

    public function mount(): void
    {
        $this->permit('view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter');
        $this->resetPage();
    }

    public function cancel(): void
    {
        $this->reset('editing', 'editingId', 'title', 'isActive', 'startsAt', 'endsAt', 'sortOrder', 'products', 'discounts', 'productSearch');
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->permit('create');
        $this->reset('editingId', 'title', 'isActive', 'startsAt', 'endsAt', 'sortOrder', 'products', 'discounts', 'productSearch');
        $this->resetValidation();
        $this->editing = true;
        $group = CustomerGroup::where('is_default', true)->first() ?? CustomerGroup::where('is_active', true)->first();
        if ($group) {
            $this->discounts = [['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]];
        }
    }

    public function edit(int $id): void
    {
        $this->permit('update');
        $bundle = Bundle::with(['products', 'discounts'])->findOrFail($id);
        $this->resetValidation();
        $this->productSearch = '';
        $this->editingId = $bundle->id;
        $this->editing = true;
        $this->title = $bundle->title;
        $this->isActive = $bundle->is_active;
        $this->startsAt = $this->formatBundleDate($bundle->starts_at);
        $this->endsAt = $this->formatBundleDate($bundle->ends_at);
        $this->sortOrder = $bundle->sort_order;
        $this->products = $bundle->products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => $p->pivot->quantity])->all();
        $this->discounts = $bundle->discounts->map(fn ($d) => ['customer_group_id' => $d->customer_group_id, 'type' => $d->type, 'value' => $d->value])->all();
        if (config('commerce.b2c_only') && $this->discounts === []) {
            $group = CustomerGroup::where('is_default', true)->first();
            if ($group) {
                $this->discounts = [['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 0]];
            }
        }
    }

    public function addProduct(int $id): void
    {
        $this->permit($this->editingId ? 'update' : 'create');
        Product::findOrFail($id);
        if (! collect($this->products)->contains('product_id', $id)) {
            $this->products[] = ['product_id' => $id, 'quantity' => 1];
        }
        $this->productSearch = '';
    }

    public function removeProduct(int $index): void
    {
        $this->permit($this->editingId ? 'update' : 'create');
        unset($this->products[$index]);
        $this->products = array_values($this->products);
    }

    public function addDiscount(): void
    {
        $this->permit($this->editingId ? 'update' : 'create');
        $this->discounts[] = ['customer_group_id' => '', 'type' => 'percent', 'value' => 0];
    }

    public function removeDiscount(int $index): void
    {
        $this->permit($this->editingId ? 'update' : 'create');
        unset($this->discounts[$index]);
        $this->discounts = array_values($this->discounts);
    }

    public function save(): void
    {
        $this->permit($this->editingId ? 'update' : 'create');
        $this->title = trim($this->title);
        $this->validate([
            'title' => 'required|string|max:255', 'isActive' => 'boolean', 'sortOrder' => 'integer|min:0|max:999999',
            'startsAt' => 'nullable|date', 'endsAt' => ['nullable', 'date', ...($this->startsAt ? ['after_or_equal:startsAt'] : [])],
            'products' => 'required|array|min:2', 'products.*.product_id' => 'required|integer|distinct|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1|max:999',
            'discounts' => 'required|array|min:1', 'discounts.*.customer_group_id' => 'required|integer|distinct|exists:customer_groups,id',
            'discounts.*.type' => ['required', Rule::in(['percent', 'fixed'])], 'discounts.*.value' => 'required|numeric|min:0|max:999999',
        ], [
            'products.required' => 'Dodajte najmanje dva različita artikla u komplet.',
            'products.min' => 'Dodajte najmanje dva različita artikla u komplet.',
            'products.*.product_id.distinct' => 'Isti artikl možete dodati samo jednom. Za više komada povećajte količinu.',
            'discounts.required' => 'Dodajte popust za komplet.',
            'discounts.min' => 'Dodajte popust za komplet.',
            'endsAt.after_or_equal' => 'Završetak ponude mora biti nakon početka ili u isto vrijeme.',
        ], [
            'title' => 'naziv ponude', 'sortOrder' => 'redoslijed', 'startsAt' => 'početak ponude', 'endsAt' => 'završetak ponude',
            'products.*.product_id' => 'artikl', 'products.*.quantity' => 'količina artikla',
            'discounts.*.customer_group_id' => 'grupa kupaca', 'discounts.*.type' => 'vrsta popusta', 'discounts.*.value' => 'vrijednost popusta',
        ]);
        foreach ($this->discounts as $i => $discount) {
            if ($discount['type'] === 'percent' && $discount['value'] > 100) {
                $this->addError('discounts.'.$i.'.value', 'Postotak mora biti između 0 i 100.');

                return;
            }
        }
        DB::transaction(function (): void {
            $bundle = $this->editingId ? Bundle::findOrFail($this->editingId) : new Bundle;
            $bundle->payload = array_merge($bundle->payload ?? [], ['admin_edited_at' => now()->toIso8601String()]);
            $bundle->fill(['title' => $this->title, 'is_active' => $this->isActive, 'starts_at' => $this->storedBundleDate($this->startsAt),
                'ends_at' => $this->storedBundleDate($this->endsAt), 'sort_order' => $this->sortOrder])->save();
            $bundle->products()->sync(collect($this->products)->mapWithKeys(fn ($p) => [$p['product_id'] => ['quantity' => $p['quantity']]])->all());
            $bundle->discounts()->delete();
            $bundle->discounts()->createMany($this->discounts);
        });
        CartService::forgetRequestSnapshot();
        app(GuestStorefrontCache::class)->invalidate();
        $this->cancel();
        session()->flash('bundle_status', 'Bundle ponuda je spremljena.');
    }

    public function delete(int $id): void
    {
        $this->permit('delete');
        Bundle::findOrFail($id)->delete();
        app(GuestStorefrontCache::class)->invalidate();
        $this->cancel();
        $this->resetPage();
        session()->flash('bundle_status', 'Bundle ponuda je obrisana.');
    }

    private function formatBundleDate(?Carbon $date): ?string
    {
        return $date === null ? null : (config('storefront.home') === 'lavaggio'
            ? $date->copy()->timezone('Europe/Zagreb')
            : $date)->format('Y-m-d\TH:i');
    }

    private function storedBundleDate(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        if (config('storefront.home') !== 'lavaggio') {
            return $value;
        }

        return Carbon::parse($value, 'Europe/Zagreb')
            ->timezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i:s');
    }

    public function render()
    {
        $this->permit('view');
        $ids = collect($this->products)->pluck('product_id');
        $search = trim($this->search);
        $productSearch = trim($this->productSearch);
        $bundles = Bundle::withCount('products')->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'));
        $now = now();
        match ($this->statusFilter) {
            'active' => $bundles->available(),
            'scheduled' => $bundles->where('is_active', true)->where('starts_at', '>', $now)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now)),
            'expired' => $bundles->where('is_active', true)->where('ends_at', '<', $now),
            'disabled' => $bundles->where('is_active', false),
            default => null,
        };

        return view('livewire.admin.catalog.bundle.manager', [
            'bundles' => $bundles->orderBy('sort_order')->orderBy('id')->paginate(20),
            'selectedProducts' => Product::with('translations')->whereIn('id', $ids)->get()->keyBy('id'),
            'productResults' => mb_strlen($productSearch) < 2 ? collect() : Product::with('translations')->whereNotIn('id', $ids)
                ->where(fn ($query) => $query->where('sku', 'like', '%'.$productSearch.'%')->orWhere('code', 'like', '%'.$productSearch.'%')
                    ->orWhereHas('translations', fn ($translations) => $translations->where('name', 'like', '%'.$productSearch.'%')))
                ->orderBy('id')->limit(15)->get(),
            'groups' => CustomerGroup::where(fn ($query) => $query->where('is_active', true)
                ->orWhereIn('id', collect($this->discounts)->pluck('customer_group_id')->filter()))->orderBy('name')->get(),
            'canCreate' => $this->can('create'), 'canUpdate' => $this->can('update'), 'canDelete' => $this->can('delete'),
            'displayTimezone' => config('storefront.home') === 'lavaggio' ? 'Europe/Zagreb' : (string) config('app.timezone', 'UTC'),
        ]);
    }
}
