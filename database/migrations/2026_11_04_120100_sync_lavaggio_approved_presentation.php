<?php

use App\Models\Content\ContentBlock;
use App\Services\Import\LavaggioPresentationSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio'
            || ! ContentBlock::query()->where('code', 'lavaggio-home-campaigns')->exists()) {
            return;
        }

        $service = app(LavaggioPresentationSyncService::class);
        $plan = $service->plan();
        DB::transaction(fn () => $service->apply($plan));
    }

    public function down(): void
    {
        // The user approved these presentation values; keep later editorial changes intact.
    }
};
