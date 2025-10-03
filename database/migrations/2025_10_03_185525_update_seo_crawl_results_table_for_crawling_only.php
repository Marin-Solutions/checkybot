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
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Remove SEO issue fields (not needed for crawling only)
            $table->dropColumn(['issues', 'is_soft_404', 'is_redirect_loop']);

            // Add crawling-specific fields
            $table->boolean('robots_txt_allowed')->default(true)->after('response_time_ms');
            $table->string('crawl_source')->default('discovery')->after('robots_txt_allowed'); // 'sitemap' or 'discovery'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Restore removed columns
            $table->json('issues')->nullable()->after('response_time_ms');
            $table->boolean('is_soft_404')->default(false)->after('image_count');
            $table->boolean('is_redirect_loop')->default(false)->after('is_soft_404');

            // Remove crawling-specific fields
            $table->dropColumn(['robots_txt_allowed', 'crawl_source']);
        });
    }
};
