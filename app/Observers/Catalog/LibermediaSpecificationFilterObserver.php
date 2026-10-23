<?php

namespace App\Observers\Catalog;

use App\Models\Catalog\Product\CatalogProductSpecification;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductTranslation;
use App\Services\Catalog\LibermediaSpecificationFilterIndex;
use Illuminate\Database\Eloquent\Model;

class LibermediaSpecificationFilterObserver
{
    public function saved(Model $model): void
    {
        $index = app(LibermediaSpecificationFilterIndex::class);
        if (! $index->enabled()) {
            return;
        }
        if ($model instanceof Product) {
            LibermediaSpecificationFilterIndex::invalidate();

            return;
        }
        if ($model instanceof ProductTranslation && ! $model->wasRecentlyCreated && ! $model->wasChanged(['description', 'locale'])) {
            return;
        }
        if ($model instanceof ProductTranslation || $model instanceof CatalogProductSpecification) {
            $product = Product::query()->find($model->product_id);
            if ($product) {
                $index->sync($product);
            }
        }
    }

    public function deleted(Model $model): void
    {
        $index = app(LibermediaSpecificationFilterIndex::class);
        if (! $index->enabled()) {
            return;
        }
        if ($model instanceof Product) {
            LibermediaSpecificationFilterIndex::invalidate();

            return;
        }
        $product = Product::query()->find($model->product_id);
        if ($product) {
            $index->sync($product);
        }
    }
}
