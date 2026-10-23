<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('target_key', 120);
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained('catalog_bundles')->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->nullable()->constrained('catalog_product_option_values')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190);
            $table->string('locale', 12)->default('hr');
            $table->timestamp('consented_at');
            $table->string('status', 24)->default('waiting')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->string('token', 64)->unique();
            $table->string('claim_token', 64)->nullable();
            $table->timestamps();
            $table->unique(['target_key', 'email']);
            $table->index(['status', 'last_attempt_at', 'id'], 'stock_notifications_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_notifications');
    }
};
