<?php

namespace App\Models\Sales\Order;

use Illuminate\Database\Eloquent\Model;

class BoxNowShipmentEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'immutable_datetime'];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
