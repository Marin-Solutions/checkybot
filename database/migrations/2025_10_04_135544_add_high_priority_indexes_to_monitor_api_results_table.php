<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            // Monitor + success filtering
            if (! $this->indexExists('monitor_api_results', 'idx_mar_is_success')) {
                $table->index(['monitor_api_id', 'is_success'], 'idx_mar_is_success');
            }

            // HTTP code filtering
            if (! $this->indexExists('monitor_api_results', 'idx_mar_http_code')) {
                $table->index('http_code', 'idx_mar_http_code');
            }

            // Performance analysis: response time over time
            if (! $this->indexExists('monitor_api_results', 'idx_mar_response_time')) {
                $table->index(['monitor_api_id', 'response_time_ms', 'created_at'], 'idx_mar_response_time');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->dropIndex('idx_mar_is_success');
            $table->dropIndex('idx_mar_http_code');
            $table->dropIndex('idx_mar_response_time');
        });
    }
};
