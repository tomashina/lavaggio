<?php

namespace App\Models\Catalog\Bundle;

use Illuminate\Database\Eloquent\Model;

class BundleDiscount extends Model
{
    protected $table = 'catalog_bundle_discounts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['value' => 'decimal:4'];
}
