<?php

namespace App\Models\Sales\Order;

use App\Models\Settings\Local\ShippingMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoxNowShipment extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'state_at' => 'immutable_datetime',
        'attempted_at' => 'immutable_datetime',
        'last_synced_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BoxNowShipmentEvent::class, 'shipment_id')->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function trackingUrl(): string
    {
        return 'https://track.boxnow.hr/?track='.rawurlencode((string) $this->parcel_id);
    }
}
