<?php

namespace App\Observers\Sales;

use App\Models\Sales\Order\Order;
use App\Services\GiftVoucherService;

class GiftVoucherOrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged(['paid_at', 'status_id'])) {
            app(GiftVoucherService::class)->syncOrder($order);
        }
    }
}
