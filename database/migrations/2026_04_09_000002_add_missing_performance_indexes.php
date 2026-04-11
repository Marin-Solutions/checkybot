<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            if (! $this->indexExists('seo_checks', 'seo_checks_website_status_finished_idx')) {
                $table->index(['website_id', 'status', 'finished_at'], 'seo_checks_website_status_finished_idx');
            }

            if (! $this->indexExists('seo_checks', 'seo_checks_status_finished_idx')) {
                $table->index(['status', 'finished_at'], 'seo_checks_status_finished_idx');
            }
        });

        Schema::table('monitor_api_results', function (Blueprint $table) {
            if (! $this->indexExists('monitor_api_results', 'monitor_api_results_monitor_id_id_idx')) {
                $table->index(['monitor_api_id', 'id'], 'monitor_api_results_monitor_id_id_idx');
            }
        });

        Schema::table('websites', function (Blueprint $table) {
            if (! $this->indexExists('websites', 'websites_uptime_check_interval_idx')) {
                $table->index(['uptime_check', 'uptime_interval'], 'websites_uptime_check_interval_idx');
            }

            if (! $this->indexExists('websites', 'websites_source_package_interval_idx')) {
                $table->index(['source', 'package_interval'], 'websites_source_package_interval_idx');
            }
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            if (! $this->indexExists('monitor_apis', 'monitor_apis_source_deleted_package_interval_idx')) {
                $table->index(['source', 'deleted_at', 'package_interval'], 'monitor_apis_source_deleted_package_interval_idx');
            }

            if (! $this->indexExists('monitor_apis', 'monitor_apis_created_by_deleted_at_idx')) {
                $table->index(['created_by', 'deleted_at'], 'monitor_apis_created_by_deleted_at_idx');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table) {
            if ($this->indexExists('monitor_apis', 'monitor_apis_source_deleted_package_interval_idx')) {
                $table->dropIndex('monitor_apis_source_deleted_package_interval_idx');
            }

            if ($this->indexExists('monitor_apis', 'monitor_apis_created_by_deleted_at_idx')) {
                $table->dropIndex('monitor_apis_created_by_deleted_at_idx');
            }
        });

        Schema::table('websites', function (Blueprint $table) {
            if ($this->indexExists('websites', 'websites_uptime_check_interval_idx')) {
                $table->dropIndex('websites_uptime_check_interval_idx');
            }

            if ($this->indexExists('websites', 'websites_source_package_interval_idx')) {
                $table->dropIndex('websites_source_package_interval_idx');
            }
        });

        Schema::table('monitor_api_results', function (Blueprint $table) {
            if ($this->indexExists('monitor_api_results', 'monitor_api_results_monitor_id_id_idx')) {
                $table->dropIndex('monitor_api_results_monitor_id_id_idx');
            }
        });

        Schema::table('seo_checks', function (Blueprint $table) {
            if ($this->indexExists('seo_checks', 'seo_checks_website_status_finished_idx')) {
                $table->dropIndex('seo_checks_website_status_finished_idx');
            }

            if ($this->indexExists('seo_checks', 'seo_checks_status_finished_idx')) {
                $table->dropIndex('seo_checks_status_finished_idx');
            }
        });
    }
};
