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
        // Add indexes for SEO checks table
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->index(['website_id', 'created_at'], 'seo_checks_website_created_idx');
            $table->index(['status', 'created_at'], 'seo_checks_status_created_idx');
        });

        // Add indexes for SEO issues table
        Schema::table('seo_issues', function (Blueprint $table) {
            $table->index(['seo_check_id', 'severity'], 'seo_issues_check_severity_idx');
            $table->index(['severity', 'created_at'], 'seo_issues_severity_created_idx');
        });

        // Add indexes for SEO crawl results table
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            $table->index(['seo_check_id', 'status_code'], 'seo_crawl_results_check_status_idx');
            $table->index(['status_code', 'created_at'], 'seo_crawl_results_status_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes for SEO checks table
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->dropIndex('seo_checks_website_created_idx');
            $table->dropIndex('seo_checks_status_created_idx');
        });

        // Drop indexes for SEO issues table
        Schema::table('seo_issues', function (Blueprint $table) {
            $table->dropIndex('seo_issues_check_severity_idx');
            $table->dropIndex('seo_issues_severity_created_idx');
        });

        // Drop indexes for SEO crawl results table
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            $table->dropIndex('seo_crawl_results_check_status_idx');
            $table->dropIndex('seo_crawl_results_status_created_idx');
        });
    }
};
