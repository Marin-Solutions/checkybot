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
        Schema::create('seo_crawl_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_check_id')->constrained()->onDelete('cascade');
            $table->string('url');
            $table->integer('status_code');
            $table->string('canonical_url')->nullable();
            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1')->nullable();
            $table->json('internal_links')->nullable(); // Array of internal links found
            $table->json('external_links')->nullable(); // Array of external links found
            $table->integer('page_size_bytes')->nullable(); // Total page size in bytes
            $table->integer('html_size_bytes')->nullable(); // HTML size in bytes
            $table->json('resource_sizes')->nullable(); // JS, CSS, images sizes
            $table->json('headers')->nullable(); // HTTP headers (noindex, nofollow, hreflang, etc.)
            $table->decimal('response_time_ms', 8, 2)->nullable(); // Time to first byte in milliseconds
            $table->json('issues')->nullable(); // Array of issues found for this URL
            $table->integer('internal_link_count')->default(0);
            $table->integer('external_link_count')->default(0);
            $table->integer('image_count')->default(0);
            $table->boolean('is_soft_404')->default(false);
            $table->boolean('is_redirect_loop')->default(false);
            $table->timestamps();

            $table->index(['seo_check_id', 'status_code']);
            $table->index(['url']);
            $table->index(['is_soft_404', 'is_redirect_loop']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_crawl_results');
    }
};
