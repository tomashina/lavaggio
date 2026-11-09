<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lavaggio_fira_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 32);
            $table->string('document_type', 32);
            $table->string('payload_hash', 64);
            $table->string('invoice_id', 64)->nullable();
            $table->string('invoice_number', 128)->nullable();
            $table->string('fiscal_status', 64)->nullable();
            $table->boolean('has_jir')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately retain the issuance ledger: losing it could allow duplicate invoices.
    }
};
