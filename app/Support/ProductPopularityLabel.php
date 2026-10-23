<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductPopularityLabel
{
    public static function isPopular(Product $product): bool
    {
        return data_get($product->payload, 'storefront.is_popular') === true;
    }

    public static function scope(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('payload').'->storefront->is_popular', true);
    }
}
