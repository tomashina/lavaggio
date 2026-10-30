<?php

namespace App\Livewire\Admin\Catalog\Product;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Pricing\ProductPricePresentationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PromotionManager extends Component
{
    #[Locked]
    public int $productId;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public string $locale = 'hr';

    public bool $editing = false;

    public array $form = [
        'title' => '',
        'type' => CatalogAction::TYPE_PERCENTAGE,
        'discount_value' => 10,
        'starts_at' => '',
        'ends_at' => '',
        'is_active' => true,
        'priority' => 100,
        'is_exclusive' => true,
    ];

    public function mount(int $productId, ?string $locale = null): void
    {
        $this->productId = $productId;
        $this->locale = (string) ($locale ?: request()->query('locale') ?: config('app.locale', 'hr'));
        $this->permit('view');
        Product::query()->findOrFail($this->productId);
    }

    public function create(): void
    {
        $this->permit('create');
        $product = Product::query()->with('translations')->findOrFail($this->productId);
        $name = $product->translations->firstWhere('locale', $this->locale)?->name ?? $product->sku ?? $product->code;
        $this->reset('editingId', 'form');
        $this->form['title'] = Str::limit('Akcija: '.$name, 255, '');
        $this->editing = true;
        $this->resetValidation();
    }

    public function edit(int $actionId): void
    {
        $this->permit('update');
        $action = $this->applicableActions()->with(['targets', 'translations'])->find($actionId);
        abort_unless($action, 404);
        abort_unless($this->canEditInline($action), 403, 'Ova akcija uređuje se u zajedničkom editoru akcija.');
        $translation = $action->translations->firstWhere('locale', $this->locale);
        $this->editingId = $action->id;
        $this->form = [
            'title' => $translation?->title ?? $action->code,
            'type' => $action->type,
            'discount_value' => (string) $action->discount_value,
            'starts_at' => $this->formatPromotionDate($action->starts_at, 'Y-m-d\TH:i:s') ?? '',
            'ends_at' => $this->formatPromotionDate($action->ends_at, 'Y-m-d\TH:i:s') ?? '',
            'is_active' => $action->is_active,
            'priority' => $action->priority,
            'is_exclusive' => $action->is_exclusive,
        ];
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->permit('view');
        $this->reset('editingId', 'editing', 'form');
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->permit($this->editingId ? 'update' : 'create');
        $validated = $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.type' => ['required', Rule::in([CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])],
            'form.discount_value' => ['required', 'numeric', 'gt:0', ($this->form['type'] ?? null) === CatalogAction::TYPE_PERCENTAGE ? 'max:100' : 'max:9999999999.99'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date', Rule::when(filled($this->form['starts_at'] ?? null), 'after_or_equal:form.starts_at')],
            'form.is_active' => ['required', 'boolean'],
            'form.priority' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'form.is_exclusive' => ['required', 'boolean'],
        ])['form'];

        DB::transaction(function () use ($validated): void {
            Product::query()->lockForUpdate()->findOrFail($this->productId);
            if ($this->editingId) {
                // Recheck the persisted scope and restrictions after every edit, not browser state.
                $action = $this->applicableActions()->lockForUpdate()->find($this->editingId);
                abort_unless($action, 403, 'Akcija više ne pripada ovom artiklu.');
                $action->setRelation('targets', $action->targets()->lockForUpdate()->get());
                abort_unless($this->canEditInline($action), 403, 'Opseg akcije se promijenio. Otvorite zajednički editor akcija.');
            } else {
                $action = new CatalogAction([
                    'code' => config('storefront.home').'-product-'.$this->productId.'-'.Str::lower(Str::random(12)),
                    'scope' => CatalogAction::SCOPE_PRODUCT,
                    'target_type' => CatalogAction::TARGET_PRODUCT,
                    'audience_type' => CatalogAction::AUDIENCE_ALL,
                    'created_by' => auth()->id(),
                ]);
            }

            $action->fill([
                'type' => $validated['type'],
                'discount_value' => $validated['discount_value'],
                'starts_at' => $this->storedPromotionDate($validated['starts_at'] ?? null),
                'ends_at' => $this->storedPromotionDate($validated['ends_at'] ?? null),
                'is_active' => $validated['is_active'],
                'priority' => $validated['priority'],
                'is_exclusive' => $validated['is_exclusive'],
                'updated_by' => auth()->id(),
            ])->save();
            if (! $this->editingId) {
                $action->targets()->create([
                    'target_type' => CatalogAction::TARGET_PRODUCT,
                    'target_id' => $this->productId,
                    'sort_order' => 0,
                ]);
            }
            // Other translation content, locales, target rows and import metadata stay intact.
            $action->translations()->updateOrCreate(['locale' => $this->locale], ['title' => trim($validated['title'])]);
            activity('catalog_actions')->performedOn($action)->causedBy(auth()->user())
                ->event($this->editingId ? 'updated' : 'created')
                ->withProperties(['product_id' => $this->productId, 'locale' => $this->locale])
                ->log('Product promotion saved');
        });

        foreach (array_keys(request()->attributes->all()) as $key) {
            if (str_starts_with($key, 'b2c.promotion')) {
                request()->attributes->remove($key);
            }
        }
        app(GuestStorefrontCache::class)->invalidate();
        $this->reset('editingId', 'editing', 'form');
        $this->dispatch('notify', type: 'success', message: __('Akcija je spremljena.'));
    }

    public function render()
    {
        $this->permit('view');
        $product = Product::query()->with(['categories', 'translations', 'taxRate'])->findOrFail($this->productId);
        $actions = $this->applicableActions($product)->with(['targets', 'translations'])
            ->orderByDesc('is_active')->orderByDesc('priority')->orderByDesc('id')->get();

        return view('livewire.admin.catalog.product.promotion-manager', [
            'promotions' => $actions->map(fn (CatalogAction $action): array => [
                'id' => $action->id,
                'title' => $action->translations->firstWhere('locale', $this->locale)?->title ?? $action->code,
                'scope' => $this->scopeLabel($action),
                'status' => match (true) {
                    ! $action->is_active => 'Isključena',
                    $action->starts_at?->isFuture() => 'Zakazana',
                    $action->ends_at?->isPast() => 'Završena',
                    default => 'Aktivna',
                },
                'discount' => match ($action->type) {
                    CatalogAction::TYPE_PERCENTAGE => rtrim(rtrim(number_format((float) $action->discount_value, 2, ',', ''), '0'), ',').' %',
                    CatalogAction::TYPE_FIXED => number_format((float) $action->discount_value, 2, ',', '.').' €',
                    default => 'Složena akcija',
                },
                'starts_at' => $this->formatPromotionDate($action->starts_at, 'd.m.Y. H:i') ?? 'Bez početnog datuma',
                'ends_at' => $this->formatPromotionDate($action->ends_at, 'd.m.Y. H:i') ?? 'Bez završnog datuma',
                'editable' => $this->canEditInline($action),
            ]),
            'price' => app(ProductPricePresentationService::class)->forProduct($product, null),
            'canCreate' => $this->can('create'),
            'canUpdate' => $this->can('update'),
        ]);
    }

    private function applicableActions(?Product $product = null): Builder
    {
        $product ??= Product::query()->findOrFail($this->productId);
        $categoryIds = $product->relationLoaded('categories')
            ? $product->categories->modelKeys()
            : $product->categories()->pluck('categories.id')->all();

        return CatalogAction::query()->where('scope', CatalogAction::SCOPE_PRODUCT)
            ->where(function (Builder $query) use ($product, $categoryIds): void {
                $query->where('target_type', CatalogAction::TARGET_ALL)
                    ->orWhere(function (Builder $query) use ($product): void {
                        $query->where('target_type', CatalogAction::TARGET_PRODUCT)
                            ->whereHas('targets', fn (Builder $target) => $target->where('target_type', CatalogAction::TARGET_PRODUCT)->where('target_id', $product->id));
                    });
                if ($categoryIds !== []) {
                    $query->orWhere(function (Builder $query) use ($categoryIds): void {
                        $query->where('target_type', CatalogAction::TARGET_CATEGORY)
                            ->whereHas('targets', fn (Builder $target) => $target->where('target_type', CatalogAction::TARGET_CATEGORY)->whereIn('target_id', $categoryIds));
                    });
                }
                if ($product->manufacturer_id) {
                    $query->orWhere(function (Builder $query) use ($product): void {
                        $query->where('target_type', CatalogAction::TARGET_MANUFACTURER)
                            ->whereHas('targets', fn (Builder $target) => $target->where('target_type', CatalogAction::TARGET_MANUFACTURER)->where('target_id', $product->manufacturer_id));
                    });
                }
            });
    }

    private function formatPromotionDate(?Carbon $date, string $format): ?string
    {
        if ($date === null) {
            return null;
        }

        return (config('storefront.home') === 'lavaggio'
            ? $date->copy()->timezone('Europe/Zagreb')
            : $date)->format($format);
    }

    private function storedPromotionDate(?string $value): ?string
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

    protected function validationAttributes(): array
    {
        return [
            'form.title' => __('Naziv akcije'),
            'form.type' => __('Vrsta popusta'),
            'form.discount_value' => __('Popust'),
            'form.starts_at' => __('Vrijedi od'),
            'form.ends_at' => __('Vrijedi do'),
            'form.is_active' => __('Akcija je aktivna'),
            'form.priority' => __('Prioritet'),
            'form.is_exclusive' => __('Isključiva akcija'),
        ];
    }

    private function canEditInline(CatalogAction $action): bool
    {
        if ($action->scope !== CatalogAction::SCOPE_PRODUCT
            || ! in_array($action->type, [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED], true)
            || $action->target_type !== CatalogAction::TARGET_PRODUCT
            || $action->audience_type !== CatalogAction::AUDIENCE_ALL
            || $action->customer_group_id || $action->role_id || $action->user_id
            || filled($action->coupon_code)
            || (float) $action->min_subtotal > 0
            || $action->buy_qty || $action->get_qty || $action->gift_product_id
            || $action->usage_limit || $action->usage_limit_per_user
            || filled(data_get($action->payload, 'conditions'))
            || filled(data_get($action->payload, 'rules'))) {
            return false;
        }

        return $action->targets->count() === 1
            && $action->targets->first()->target_type === CatalogAction::TARGET_PRODUCT
            && $action->targets->first()->target_id === $this->productId;
    }

    private function scopeLabel(CatalogAction $action): string
    {
        $label = match ($action->target_type) {
            CatalogAction::TARGET_ALL => 'Svi artikli',
            CatalogAction::TARGET_CATEGORY => 'Kategorijska akcija',
            CatalogAction::TARGET_MANUFACTURER => 'Akcija branda',
            default => $action->targets->count() === 1 ? 'Samo ovaj artikl' : 'Više artikala ('.$action->targets->count().')',
        };
        if (filled($action->coupon_code)) {
            $label .= ' · Kupon';
        }
        if ($action->audience_type !== CatalogAction::AUDIENCE_ALL) {
            $label .= ' · Odabrani kupci';
        }

        return $label;
    }

    private function can(string $ability): bool
    {
        $user = auth()->user();

        return $user && ($user->isA('superadmin')
            || ($user->can('admin.access') && $user->can('catalog.products.update') && $user->can('catalog.actions.'.$ability)));
    }

    private function permit(string $ability): void
    {
        abort_unless(in_array(config('storefront.home'), ['watchline', 'lavaggio'], true) && app(CatalogFeatureService::class)->useActions(), 404);
        abort_unless($this->can('view') && $this->can($ability), 403);
    }
}
