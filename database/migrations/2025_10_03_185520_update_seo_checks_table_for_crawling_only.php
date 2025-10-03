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
        Schema::table('seo_checks', function (Blueprint $table) {
            // Remove health score and issue counting fields (not needed for crawling only)
            $table->dropColumn(['health_score', 'errors_found', 'warnings_found', 'notices_found']);

            // Add crawling-specific fields
            $table->integer('total_crawlable_urls')->default(0)->after('total_urls_crawled');
            $table->boolean('sitemap_used')->default(false)->after('total_crawlable_urls');
            $table->boolean('robots_txt_checked')->default(false)->after('sitemap_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            // Restore removed columns
            $table->integer('health_score')->nullable()->after('status');
            $table->integer('errors_found')->default(0)->after('total_urls_crawled');
            $table->integer('warnings_found')->default(0)->after('errors_found');
            $table->integer('notices_found')->default(0)->after('warnings_found');

            // Remove crawling-specific fields
            $table->dropColumn(['total_crawlable_urls', 'sitemap_used', 'robots_txt_checked']);
        });
    }
};
