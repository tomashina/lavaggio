<?php

namespace App\Models\Sales\Order;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Concerns\HasB2BMonetaryPrecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasB2BMonetaryPrecision;

    protected array $b2bMonetaryAttributes = ['unit_price', 'discount_amount', 'tax_amount', 'line_total'];

    protected $fillable = [
        'order_id',
        'product_id',
        'product_option_value_id',
        'sku',
        'code',
        'name',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'quantity',
        'line_total',
        'sort_order',
        'payload',
    ];

    protected $casts = [
        'order_id' => 'int',
        'product_id' => 'int',
        'product_option_value_id' => 'int',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'quantity' => 'int',
        'line_total' => 'decimal:2',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function grossLineTotal(): float
    {
        return round((float) $this->line_total + (float) $this->tax_amount, 2);
    }

    public function grossUnitPrice(): float
    {
        return $this->grossLineTotal() / max(1, (int) $this->quantity);
    }

    public function grossBaseTotal(): float
    {
        $unit = data_get($this->payload, 'pricing.base_unit_gross');
        if (is_numeric($unit)) {
            return round($unit * $this->quantity, 2);
        }
        if ((float) $this->discount_amount === 0.0) {
            return $this->grossLineTotal();
        }

        // Older checkout snapshots contain the base unit net and the historical VAT rate.
        return round((float) $this->unit_price * (1 + (float) $this->tax_rate / 100), 2) * $this->quantity;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productOptionValue(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'product_option_value_id');
    }
}
