<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductNewLabel
{
    public static function isNew(Product $product): bool
    {
        return data_get($product->payload, 'storefront.is_new') === true;
    }

    public static function scope(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('payload').'->storefront->is_new', true);
    }
}
