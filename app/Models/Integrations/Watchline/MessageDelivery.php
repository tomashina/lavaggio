<?php

namespace App\Models\Integrations\Watchline;

use Illuminate\Database\Eloquent\Model;

class MessageDelivery extends Model
{
    protected $table = 'watchline_message_deliveries';

    protected $guarded = [];

    protected $hidden = ['recipient', 'email_hash', 'payload'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'payload' => 'array', 'claimed_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
