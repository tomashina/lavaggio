<?php

namespace App\Livewire\Admin\Catalog\Bundle;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\GuestStorefrontCache;
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
        $user = auth()->user();
        abort_unless($user && ($user->isA('superadmin') || $user->can('catalog.actions.'.$ability)), 403);
    }

    public function mount(): void
    {
        $this->permit('view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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
        $this->editingId = $bundle->id;
        $this->editing = true;
        $this->title = $bundle->title;
        $this->isActive = $bundle->is_active;
        $this->startsAt = $bundle->starts_at?->format('Y-m-d\TH:i');
        $this->endsAt = $bundle->ends_at?->format('Y-m-d\TH:i');
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
        $this->validate([
            'title' => 'required|string|max:255', 'isActive' => 'boolean', 'sortOrder' => 'integer|min:0|max:999999',
            'startsAt' => 'nullable|date', 'endsAt' => ['nullable', 'date', ...($this->startsAt ? ['after_or_equal:startsAt'] : [])],
            'products' => 'required|array|min:2', 'products.*.product_id' => 'required|integer|distinct|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1|max:999',
            'discounts' => 'required|array|min:1', 'discounts.*.customer_group_id' => 'required|integer|distinct|exists:customer_groups,id',
            'discounts.*.type' => ['required', Rule::in(['percent', 'fixed'])], 'discounts.*.value' => 'required|numeric|min:0|max:999999',
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
            $bundle->fill(['title' => $this->title, 'is_active' => $this->isActive, 'starts_at' => $this->startsAt ?: null,
                'ends_at' => $this->endsAt ?: null, 'sort_order' => $this->sortOrder])->save();
            $bundle->products()->sync(collect($this->products)->mapWithKeys(fn ($p) => [$p['product_id'] => ['quantity' => $p['quantity']]])->all());
            $bundle->discounts()->delete();
            $bundle->discounts()->createMany($this->discounts);
        });
        CartService::forgetRequestSnapshot();
        app(GuestStorefrontCache::class)->invalidate();
        $this->editing = false;
        session()->flash('bundle_status', 'Bundle ponuda je spremljena.');
    }

    public function delete(int $id): void
    {
        $this->permit('delete');
        Bundle::findOrFail($id)->delete();
        app(GuestStorefrontCache::class)->invalidate();
        $this->editing = false;
    }

    public function render()
    {
        $this->permit('view');
        $ids = collect($this->products)->pluck('product_id');

        return view('livewire.admin.catalog.bundle.manager', [
            'bundles' => Bundle::withCount('products')->when($this->search, fn ($q) => $q->where('title', 'like', '%'.$this->search.'%'))->orderBy('sort_order')->orderBy('id')->paginate(20),
            'selectedProducts' => Product::with('translations')->whereIn('id', $ids)->get()->keyBy('id'),
            'productResults' => mb_strlen(trim($this->productSearch)) < 2 ? collect() : Product::with('translations')->whereNotIn('id', $ids)
                ->where(fn ($q) => $q->where('sku', 'like', '%'.$this->productSearch.'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$this->productSearch.'%')))->limit(15)->get(),
            'groups' => CustomerGroup::where('is_active', true)->get(),
        ]);
    }
}
