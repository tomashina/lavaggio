<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_history_id')->nullable()->constrained('order_history')->nullOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('type', 30);
            $table->string('recipient');
            $table->string('subject');
            $table->string('status', 20)->default('pending')->index();
            $table->json('content');
            $table->json('attempts')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
