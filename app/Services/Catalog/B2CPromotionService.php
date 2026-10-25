<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Action\CatalogActionTarget;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Request-local promotion sets shared by catalog filters and every product card. */
class B2CPromotionService
{
    public function actions(?User $user = null, string $coupon = '', bool $history = false): Collection
    {
        $set = $this->promotionSet($user, $coupon, $history);

        return $this->hydrateMatches($set, array_keys($set->positions));
    }

    private function promotionSet(?User $user, string $coupon = '', bool $history = false): \stdClass
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
            ->orderByDesc('is_exclusive')->orderByDesc('priority')->orderByDesc('id');
        if ($history) {
            $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->subDays(30)));
        } else {
            $query->active();
        }
        // A catalogue may contain thousands of independent product schedules.
        // Keep their complete database attributes, but only hydrate the few
        // actions and targets actually needed by the products being displayed.
        $rows = $query->toBase()->get();
        $set = (object) ['rows' => [], 'targets' => [], 'models' => [], 'positions' => [], 'all' => [], 'matches' => [], 'filters' => [],
            'actionModel' => $query->getModel(), 'targetModel' => new CatalogActionTarget];
        foreach ($rows as $position => $row) {
            $id = (int) $row->id;
            $set->rows[$id] = $row;
            $set->positions[$id] = $position;
            if ($row->target_type === CatalogAction::TARGET_ALL) {
                $set->all[$id] = true;
            }
        }
        if ($set->rows !== []) {
            $targets = CatalogActionTarget::query()->whereIntegerInRaw('action_id', array_keys($set->rows))
                ->orderBy('sort_order')->orderBy('id')->toBase()->get();
            foreach ($targets as $target) {
                $actionId = (int) $target->action_id;
                $targetId = (int) $target->target_id;
                $set->targets[$actionId][] = $target;
                $set->filters[$target->target_type][$targetId] = true;
                if ($set->rows[$actionId]->target_type === $target->target_type) {
                    $set->matches[$target->target_type][$targetId][$actionId] = true;
                }
            }
        }
        $request->attributes->set($key, $set);

        return $set;
    }

    public function forProduct(Product $product, ?User $user = null, string $coupon = '', bool $history = false): Collection
    {
        $set = $this->promotionSet($user, $coupon, $history);
        $matches = $set->all
            + ($set->matches[CatalogAction::TARGET_PRODUCT][(int) $product->id] ?? [])
            + ($set->matches[CatalogAction::TARGET_MANUFACTURER][(int) $product->manufacturer_id] ?? []);
        if (! empty($set->matches[CatalogAction::TARGET_CATEGORY])) {
            $categoryIds = $product->relationLoaded('categories')
                ? $product->categories->modelKeys()
                : $product->categories()->pluck('categories.id')->all();
            foreach ($categoryIds as $categoryId) {
                $matches += $set->matches[CatalogAction::TARGET_CATEGORY][(int) $categoryId] ?? [];
            }
        }

        // Lookup only matching IDs; filtering the full action collection here
        // makes catalogue-wide price comparisons products × all promotions.
        return $this->hydrateMatches($set, array_keys($matches));
    }

    public function filter(Builder $query, ?User $user = null): void
    {
        $set = $this->promotionSet($user);
        if ($set->all !== []) {
            return;
        }
        $products = array_keys($set->filters[CatalogAction::TARGET_PRODUCT] ?? []);
        $categories = array_keys($set->filters[CatalogAction::TARGET_CATEGORY] ?? []);
        $manufacturers = array_keys($set->filters[CatalogAction::TARGET_MANUFACTURER] ?? []);
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

    private function hydrateMatches(\stdClass $set, array $ids): Collection
    {
        usort($ids, fn (int $first, int $second): int => $set->positions[$first] <=> $set->positions[$second]);
        $resolved = [];
        $actionModel = $set->actionModel;
        $targetModel = $set->targetModel;
        foreach ($ids as $id) {
            if (! isset($set->models[$id])) {
                $action = $actionModel->newFromBuilder((array) $set->rows[$id]);
                $targets = array_map(fn ($target) => $targetModel->newFromBuilder((array) $target), $set->targets[$id] ?? []);
                $action->setRelation('targets', $targetModel->newCollection($targets));
                $set->models[$id] = $action;
                unset($set->rows[$id], $set->targets[$id]);
            }
            $resolved[$set->positions[$id]] = $set->models[$id];
        }

        return $actionModel->newCollection($resolved);
    }
}
