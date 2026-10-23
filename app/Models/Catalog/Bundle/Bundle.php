<?php

namespace App\Models\Catalog\Bundle;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bundle extends Model
{
    protected $table = 'catalog_bundles';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'payload' => 'array'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'catalog_bundle_products', 'bundle_id', 'product_id')->withPivot('quantity');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(BundleDiscount::class, 'bundle_id');
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
