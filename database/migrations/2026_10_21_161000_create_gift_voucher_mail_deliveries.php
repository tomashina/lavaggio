<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_voucher_mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 120)->unique();
            $table->string('kind', 32);
            $table->foreignId('gift_voucher_id')->nullable()->constrained('gift_vouchers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->string('recipient_email', 191);
            $table->string('status', 24)->default('waiting');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_attempt_at', 'id'], 'gift_voucher_mail_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_voucher_mail_deliveries');
    }
};
