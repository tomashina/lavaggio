<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_now_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->string('order_number', 40)->unique();
            $table->string('api_url');
            $table->string('reference_number')->nullable();
            $table->string('parcel_id', 120)->nullable()->unique();
            $table->string('state', 60)->nullable()->index();
            $table->dateTime('state_at', 6)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->string('label_path')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('box_now_shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('box_now_shipments')->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->string('state', 60);
            $table->dateTime('occurred_at', 6);
            $table->string('location')->nullable();
            $table->string('source', 20);
            $table->timestamps();
            $table->unique(['shipment_id', 'event_key']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('box_now_shipment_events');
        Schema::dropIfExists('box_now_shipments');
        // Keep status definitions: existing order history may still reference them.
    }
};
