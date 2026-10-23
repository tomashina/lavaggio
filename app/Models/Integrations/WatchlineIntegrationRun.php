<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Model;

class WatchlineIntegrationRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['dry_run' => 'boolean', 'summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
