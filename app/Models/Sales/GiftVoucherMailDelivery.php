<?php

namespace App\Models\Sales;

use App\Models\Sales\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftVoucherMailDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['claim_token'];

    protected $casts = ['attempts' => 'integer', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(GiftVoucher::class, 'gift_voucher_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
