<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchline_abandoned_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('session_hash', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('email')->nullable();
            $table->string('email_hash', 64)->nullable()->index();
            $table->text('customer_name')->nullable();
            $table->string('locale', 12)->default('hr');
            $table->json('items');
            $table->string('fingerprint', 64);
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('last_activity_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('watchline_message_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('job', 24);
            $table->string('target_id', 64);
            $table->string('dedup_key', 64)->unique();
            $table->string('email_hash', 64)->index();
            $table->text('recipient');
            $table->string('status', 24)->default('claimed');
            $table->timestamp('claimed_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        Schema::create('watchline_message_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('job', 24);
            $table->string('email_hash', 64);
            $table->timestamps();
            $table->unique(['job', 'email_hash'], 'watchline_message_suppression_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchline_message_suppressions');
        Schema::dropIfExists('watchline_message_deliveries');
        Schema::dropIfExists('watchline_abandoned_carts');
    }
};
