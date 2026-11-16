<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        // Correct only the requested import date; preserve baseline amounts and other dates.
        DB::table('products')->whereDate('listed_at', '2026-11-13')
            ->update(['listed_at' => '2026-11-16', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not revert dates subsequently edited by the administrator.
    }
};
