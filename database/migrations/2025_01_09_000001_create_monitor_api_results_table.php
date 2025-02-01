<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_api_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_api_id')->constrained('monitor_apis')->cascadeOnDelete();
            $table->boolean('is_success');
            $table->integer('consecutive_count')->default(1); // Number of consecutive successes or failures
            $table->integer('response_time_ms'); // Response time in milliseconds
            $table->integer('http_code'); // HTTP response code
            $table->json('failed_assertions')->nullable(); // Store failed assertions if any
            $table->timestamps();

            // Index for efficient querying
            $table->index(['monitor_api_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_api_results');
    }
};
