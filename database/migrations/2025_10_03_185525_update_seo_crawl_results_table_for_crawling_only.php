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
        // Check which columns exist before attempting to drop
        $hasIsSoft404 = Schema::hasColumn('seo_crawl_results', 'is_soft_404');
        $hasIsRedirectLoop = Schema::hasColumn('seo_crawl_results', 'is_redirect_loop');

        $columnsToDrop = [];
        if (Schema::hasColumn('seo_crawl_results', 'issues')) {
            $columnsToDrop[] = 'issues';
        }
        if ($hasIsSoft404) {
            $columnsToDrop[] = 'is_soft_404';
        }
        if ($hasIsRedirectLoop) {
            $columnsToDrop[] = 'is_redirect_loop';
        }

        Schema::table('seo_crawl_results', function (Blueprint $table) use ($columnsToDrop, $hasIsSoft404, $hasIsRedirectLoop) {
            // Drop index on is_soft_404 and is_redirect_loop if it exists (required before dropping columns in SQLite)
            if (($hasIsSoft404 || $hasIsRedirectLoop) && Schema::hasIndex('seo_crawl_results', 'seo_crawl_results_is_soft_404_is_redirect_loop_index')) {
                $table->dropIndex('seo_crawl_results_is_soft_404_is_redirect_loop_index');
            }

            // Remove SEO issue fields (not needed for crawling only)
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }

            // Add crawling-specific fields if they don't exist
            if (! Schema::hasColumn('seo_crawl_results', 'robots_txt_allowed')) {
                $table->boolean('robots_txt_allowed')->default(true)->after('response_time_ms');
            }
            if (! Schema::hasColumn('seo_crawl_results', 'crawl_source')) {
                $table->string('crawl_source')->default('discovery')->after('robots_txt_allowed'); // 'sitemap' or 'discovery'
            }
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
