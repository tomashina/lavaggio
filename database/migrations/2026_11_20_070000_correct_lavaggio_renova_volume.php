<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') === 'lavaggio') {
            DB::table('products')->where('sku', '200050')->where('code', 'lavaggio-200050')
                ->where('barcode', '8054729631818')->where('content_unit', 'l')->where('content_quantity', 0.5)
                ->update(['content_quantity' => 1, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The supplier and the merchant both identify this bottle as 1 L.
    }
};
