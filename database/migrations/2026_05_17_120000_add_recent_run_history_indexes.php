<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table): void {
            if (! Schema::hasIndex('monitor_api_results', 'monitor_api_results_created_id_idx')) {
                $table->index(['created_at', 'id'], 'monitor_api_results_created_id_idx');
            }
        });

        Schema::table('website_log_history', function (Blueprint $table): void {
            if (! Schema::hasIndex('website_log_history', 'website_log_history_created_id_idx')) {
                $table->index(['created_at', 'id'], 'website_log_history_created_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('website_log_history', function (Blueprint $table): void {
            if (Schema::hasIndex('website_log_history', 'website_log_history_created_id_idx')) {
                $table->dropIndex('website_log_history_created_id_idx');
            }
        });

        Schema::table('monitor_api_results', function (Blueprint $table): void {
            if (Schema::hasIndex('monitor_api_results', 'monitor_api_results_created_id_idx')) {
                $table->dropIndex('monitor_api_results_created_id_idx');
            }
        });
    }
};
