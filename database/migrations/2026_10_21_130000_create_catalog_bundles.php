<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_bundles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_id')->nullable()->unique();
            $table->string('title');
            $table->boolean('is_active')->default(false)->index();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_bundle_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')->constrained('catalog_bundles')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unique(['bundle_id', 'product_id']);
        });
        Schema::create('catalog_bundle_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')->constrained('catalog_bundles')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('value', 12, 4)->default(0);
            $table->unique(['bundle_id', 'customer_group_id']);
        });
        Schema::create('libermedia_import_records', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 80);
            $table->string('source_id', 120);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 40)->default('imported');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['entity', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libermedia_import_records');
        Schema::dropIfExists('catalog_bundle_discounts');
        Schema::dropIfExists('catalog_bundle_products');
        Schema::dropIfExists('catalog_bundles');
    }
};
