<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('cart_item_key', 64)->nullable();
            $table->string('code_hash', 64)->nullable()->unique();
            $table->text('code_ciphertext')->nullable();
            $table->string('code_suffix', 10)->nullable()->index();
            $table->decimal('initial_amount', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('buyer_name', 191)->nullable();
            $table->string('buyer_email', 191)->nullable();
            $table->string('recipient_name', 191)->nullable();
            $table->string('recipient_email', 191);
            $table->string('sender_name', 191)->nullable();
            $table->text('message')->nullable();
            $table->string('locale', 12)->default('hr');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('last_email_sent_at')->nullable();
            $table->text('email_error')->nullable();
            $table->unsignedInteger('email_attempts')->default(0);
            $table->string('email_claim_token', 64)->nullable();
            $table->timestamp('email_claimed_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'cart_item_key']);
        });
        Schema::create('gift_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gift_voucher_id')->constrained('gift_vouchers')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 24)->default('reserved')->index();
            $table->timestamp('reserved_until')->nullable()->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_voucher_redemptions');
        Schema::dropIfExists('gift_vouchers');
    }
};
