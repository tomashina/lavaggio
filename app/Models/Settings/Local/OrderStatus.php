<?php

namespace App\Models\Settings\Local;

use App\Support\OrderStatusLabels;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'color',
        'is_default',
        'is_paid',
        'is_cancelled',
        'is_active',
        'sort_order',
        'settings',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): string => OrderStatusLabels::display((string) $value),
        );
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_paid' => 'boolean',
            'is_cancelled' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
        ];
    }
}
