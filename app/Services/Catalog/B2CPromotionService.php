<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Request-local promotion sets shared by catalog filters and every product card. */
class B2CPromotionService
{
    public function actions(?User $user = null, string $coupon = '', bool $history = false): Collection
    {
        $key = 'b2c.promotions.'.($user?->id ?? 0).'.'.hash('sha256', $coupon).'.'.(int) $history;
        $request = request();
        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }
        $query = CatalogAction::query()->where('scope', CatalogAction::SCOPE_PRODUCT)
            ->whereIn('type', [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])
            ->where('discount_value', '>', 0)
            ->where(fn ($q) => $q->where('audience_type', 'all')->when($user, fn ($q) => $q->orWhere(fn ($q) => $q->where('audience_type', 'user')->where('user_id', $user->id))))
            ->where(fn ($q) => $q->whereNull('coupon_code')->orWhere('coupon_code', '')->when($coupon !== '', fn ($q) => $q->orWhereRaw('UPPER(coupon_code) = ?', [strtoupper($coupon)])))
            ->with('targets')->orderByDesc('is_exclusive')->orderByDesc('priority')->orderByDesc('id');
        if ($history) {
            $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->subDays(30)));
        } else {
            $query->active();
        }
        $actions = $query->get();
        $request->attributes->set($key, $actions);

        return $actions;
    }

    public function forProduct(Product $product, ?User $user = null, string $coupon = '', bool $history = false): Collection
    {
        $actions = $this->actions($user, $coupon, $history);
        $key = 'b2c.promotion-target-index.'.($user?->id ?? 0).'.'.hash('sha256', $coupon).'.'.(int) $history.'.'.spl_object_id($actions);
        $request = request();
        if (! $request->attributes->has($key)) {
            $index = ['all' => []];
            foreach ($actions as $action) {
                if ($action->target_type === CatalogAction::TARGET_ALL) {
                    $index['all'][(int) $action->id] = true;

                    continue;
                }
                foreach ($action->targets as $target) {
                    if ($target->target_type === $action->target_type) {
                        $index[$action->target_type][(int) $target->target_id][(int) $action->id] = true;
                    }
                }
            }
            $request->attributes->set($key, $index);
        }
        $index = $request->attributes->get($key);
        $matches = $index['all']
            + ($index[CatalogAction::TARGET_PRODUCT][(int) $product->id] ?? [])
            + ($index[CatalogAction::TARGET_MANUFACTURER][(int) $product->manufacturer_id] ?? []);
        if (! empty($index[CatalogAction::TARGET_CATEGORY])) {
            $categoryIds = $product->relationLoaded('categories')
                ? $product->categories->modelKeys()
                : $product->categories()->pluck('categories.id')->all();
            foreach ($categoryIds as $categoryId) {
                $matches += $index[CatalogAction::TARGET_CATEGORY][(int) $categoryId] ?? [];
            }
        }

        // Preserve priority/exclusivity order while avoiding rescanning every
        // promotion's complete target list for every card and historical price.
        return $actions->filter(fn ($action): bool => isset($matches[(int) $action->id]));
    }

    public function filter(Builder $query, ?User $user = null): void
    {
        $actions = $this->actions($user);
        if ($actions->contains('target_type', CatalogAction::TARGET_ALL)) {
            return;
        }
        $targets = $actions->flatMap->targets;
        $products = $targets->where('target_type', CatalogAction::TARGET_PRODUCT)->pluck('target_id')->unique()->values()->all();
        $categories = $targets->where('target_type', CatalogAction::TARGET_CATEGORY)->pluck('target_id')->unique()->values()->all();
        $manufacturers = $targets->where('target_type', CatalogAction::TARGET_MANUFACTURER)->pluck('target_id')->unique()->values()->all();
        $query->where(function ($q) use ($products, $categories, $manufacturers): void {
            $q->whereIn('products.id', $products);
            if ($manufacturers) {
                $q->orWhereIn('products.manufacturer_id', $manufacturers);
            }
            if ($categories) {
                $q->orWhereIn('products.id', fn ($sub) => $sub->select('product_id')->from('category_product')->whereIn('category_id', $categories));
            }
        });
    }
}
