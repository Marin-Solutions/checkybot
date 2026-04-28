<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_log_history', function (Blueprint $table) {
            $table->string('run_source', 40)->default('scheduled')->after('transport_error_code');
            $table->boolean('is_on_demand')->default(false)->after('run_source');
            $table->index(['is_on_demand', 'status', 'created_at'], 'website_log_history_run_source_incident_idx');
        });

        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->string('run_source', 40)->default('scheduled')->after('response_headers');
            $table->boolean('is_on_demand')->default(false)->after('run_source');
            $table->index(['is_on_demand', 'status', 'created_at'], 'monitor_api_results_run_source_incident_idx');
        });
    }

    public function down(): void
    {
        Schema::table('website_log_history', function (Blueprint $table) {
            $table->dropIndex('website_log_history_run_source_incident_idx');
            $table->dropColumn(['run_source', 'is_on_demand']);
        });

        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->dropIndex('monitor_api_results_run_source_incident_idx');
            $table->dropColumn(['run_source', 'is_on_demand']);
        });
    }
};
