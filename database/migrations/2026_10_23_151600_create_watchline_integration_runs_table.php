<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchline_integration_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('job', 32)->index();
            $table->string('trigger', 16);
            $table->boolean('dry_run')->default(true);
            $table->string('status', 32)->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchline_integration_runs');
    }
};
