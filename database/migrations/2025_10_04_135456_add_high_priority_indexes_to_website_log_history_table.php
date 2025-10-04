<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_log_history', function (Blueprint $table) {
            // Website + created_at + http status (common pattern: filter by website, order by date, check status)
            if (! $this->indexExists('website_log_history', 'idx_wlh_website_created_http')) {
                $table->index(['website_id', 'created_at', 'http_status_code'], 'idx_wlh_website_created_http');
            }

            // HTTP status code filtering (e.g., finding failed requests)
            if (! $this->indexExists('website_log_history', 'idx_wlh_http_status_code')) {
                $table->index('http_status_code', 'idx_wlh_http_status_code');
            }

            // SSL certificate expiry monitoring
            if (! $this->indexExists('website_log_history', 'idx_wlh_ssl_expiry')) {
                $table->index(['website_id', 'ssl_expiry_date'], 'idx_wlh_ssl_expiry');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('website_log_history', function (Blueprint $table) {
            $table->dropIndex('idx_wlh_website_created_http');
            $table->dropIndex('idx_wlh_http_status_code');
            $table->dropIndex('idx_wlh_ssl_expiry');
        });
    }
};
