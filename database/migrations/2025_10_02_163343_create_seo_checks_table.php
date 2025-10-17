<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seo_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->integer('health_score')->nullable(); // Percentage score 0-100
            $table->integer('total_urls_crawled')->default(0);
            $table->integer('errors_found')->default(0);
            $table->integer('warnings_found')->default(0);
            $table->integer('notices_found')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('crawl_summary')->nullable(); // Store summary statistics
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['started_at', 'finished_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_checks');
    }
};
