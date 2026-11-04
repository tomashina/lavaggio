<?php

use App\Models\Content\Page\InfoPage;
use App\Services\Import\LavaggioFeedbackUpdateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        DB::transaction(function (): void {
            // Fresh installations have no imported customer content to reconcile.
            if (! InfoPage::query()->where('code', 'like', 'lavaggio-modx-%')->exists()) {
                return;
            }

            $service = app(LavaggioFeedbackUpdateService::class);
            $service->apply($service->plan());
        });
    }

    public function down(): void
    {
        // Do not reactivate retired services or overwrite subsequent editorial changes.
    }
};
