<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_components', function (Blueprint $table): void {
            if (! $this->indexExists('project_components', 'project_components_created_by_project_archived_idx')) {
                $table->index(
                    ['created_by', 'project_id', 'is_archived'],
                    'project_components_created_by_project_archived_idx'
                );
            }
        });

        Schema::table('monitor_api_results', function (Blueprint $table): void {
            if (! $this->indexExists('monitor_api_results', 'mar_monitor_on_demand_id_idx')) {
                $table->index(
                    ['monitor_api_id', 'is_on_demand', 'id'],
                    'mar_monitor_on_demand_id_idx'
                );
            }

            if (! $this->indexExists('monitor_api_results', 'mar_monitor_on_demand_created_idx')) {
                $table->index(
                    ['monitor_api_id', 'is_on_demand', 'created_at'],
                    'mar_monitor_on_demand_created_idx'
                );
            }
        });

        Schema::table('website_log_history', function (Blueprint $table): void {
            if (! $this->indexExists('website_log_history', 'wlh_website_on_demand_created_id_idx')) {
                $table->index(
                    ['website_id', 'is_on_demand', 'created_at', 'id'],
                    'wlh_website_on_demand_created_id_idx'
                );
            }
        });

        Schema::table('seo_issues', function (Blueprint $table): void {
            if (
                $this->indexExists('seo_issues', 'seo_issues_check_severity_idx')
                && $this->indexExists('seo_issues', 'seo_issues_seo_check_id_severity_index')
            ) {
                $table->dropIndex('seo_issues_seo_check_id_severity_index');
            }
        });

        Schema::table('seo_crawl_results', function (Blueprint $table): void {
            if (
                $this->indexExists('seo_crawl_results', 'seo_crawl_results_check_status_idx')
                && $this->indexExists('seo_crawl_results', 'seo_crawl_results_seo_check_id_status_code_index')
            ) {
                $table->dropIndex('seo_crawl_results_seo_check_id_status_code_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table): void {
            if (! $this->indexExists('seo_crawl_results', 'seo_crawl_results_seo_check_id_status_code_index')) {
                $table->index(
                    ['seo_check_id', 'status_code'],
                    'seo_crawl_results_seo_check_id_status_code_index'
                );
            }
        });

        Schema::table('seo_issues', function (Blueprint $table): void {
            if (! $this->indexExists('seo_issues', 'seo_issues_seo_check_id_severity_index')) {
                $table->index(
                    ['seo_check_id', 'severity'],
                    'seo_issues_seo_check_id_severity_index'
                );
            }
        });

        Schema::table('website_log_history', function (Blueprint $table): void {
            if ($this->indexExists('website_log_history', 'wlh_website_on_demand_created_id_idx')) {
                $table->dropIndex('wlh_website_on_demand_created_id_idx');
            }
        });

        Schema::table('monitor_api_results', function (Blueprint $table): void {
            if ($this->indexExists('monitor_api_results', 'mar_monitor_on_demand_created_idx')) {
                $table->dropIndex('mar_monitor_on_demand_created_idx');
            }

            if ($this->indexExists('monitor_api_results', 'mar_monitor_on_demand_id_idx')) {
                $table->dropIndex('mar_monitor_on_demand_id_idx');
            }
        });

        Schema::table('project_components', function (Blueprint $table): void {
            if ($this->indexExists('project_components', 'project_components_created_by_project_archived_idx')) {
                $table->dropIndex('project_components_created_by_project_archived_idx');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }
};
