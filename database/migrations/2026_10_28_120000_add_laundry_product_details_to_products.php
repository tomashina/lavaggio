<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_professional')->default(false)->index();
            $table->decimal('content_quantity', 10, 3)->nullable();
            $table->string('content_unit', 12)->nullable();
            $table->decimal('listing_price', 12, 2)->nullable();
            $table->date('listed_at')->nullable();
            $table->string('delivery_mode', 24)->default('stock');
            $table->longText('ingredients')->nullable();
            $table->longText('hazard_notes')->nullable();
            $table->longText('manufacturer_importer')->nullable();
            $table->string('country_of_origin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_professional']);
            $table->dropColumn([
                'is_professional', 'content_quantity', 'content_unit', 'listing_price',
                'listed_at', 'delivery_mode', 'ingredients', 'hazard_notes',
                'manufacturer_importer', 'country_of_origin',
            ]);
        });
    }
};
