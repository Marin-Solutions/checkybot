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
        Schema::create('seo_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_check_id')->constrained()->onDelete('cascade');
            $table->foreignId('seo_crawl_result_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('type'); // e.g., 'broken_internal_link', 'redirect_loop', 'canonical_error', etc.
            $table->string('severity'); // 'error', 'warning', 'notice'
            $table->string('url'); // The URL where the issue was found
            $table->string('title'); // Human-readable title for the issue
            $table->text('description'); // Detailed description of the issue
            $table->json('data')->nullable(); // Additional data specific to the issue type
            $table->timestamps();

            $table->index(['seo_check_id', 'severity']);
            $table->index(['type', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_issues');
    }
};
