<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Drop the index first to avoid key length issues
            $table->dropIndex('seo_crawl_results_url_index');
        });

        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Increase URL column length to handle long URLs (especially from GitHub)
            $table->string('url', 1000)->change();
        });

        // Recreate the index with a prefix using raw SQL (first 191 chars to stay within limits)
        DB::statement('ALTER TABLE seo_crawl_results ADD INDEX seo_crawl_results_url_index (url(191))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Drop the prefix index
            $table->dropIndex('seo_crawl_results_url_index');
        });

        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Revert URL column length back to original
            $table->string('url', 255)->change();
        });

        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Recreate the original full-column index
            $table->index('url');
        });
    }
};
