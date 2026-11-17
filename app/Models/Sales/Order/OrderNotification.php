<?php

namespace App\Models\Sales\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderNotification extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'content' => 'array',
        'attempts' => 'array',
        'sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, ['pending', 'failed', 'skipped'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'sent' => 'Poslano',
            'failed' => 'Slanje nije uspjelo',
            'skipped' => 'Nije poslano',
            'sending' => 'Slanje u tijeku',
            default => 'Čeka slanje',
        };
    }
}
