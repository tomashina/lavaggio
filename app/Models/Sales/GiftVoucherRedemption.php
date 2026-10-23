<?php

namespace App\Models\Sales;

use App\Models\Sales\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftVoucherRedemption extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_RELEASED = 'released';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'reserved_until' => 'datetime', 'redeemed_at' => 'datetime', 'released_at' => 'datetime'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(GiftVoucher::class, 'gift_voucher_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
