<?php

namespace App\Models\Integrations\Watchline;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AbandonedCart extends Model
{
    use HasUuids;

    protected $table = 'watchline_abandoned_carts';

    protected $guarded = [];

    protected $hidden = ['session_hash', 'email', 'email_hash', 'customer_name'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'customer_name' => 'encrypted', 'items' => 'array',
            'consent_at' => 'datetime', 'last_activity_at' => 'datetime', 'closed_at' => 'datetime',
            'converted_at' => 'datetime', 'recovered_at' => 'datetime'];
    }
}
