<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if (! $this->indexExists('websites', 'websites_ssl_check_expiry_id_idx')) {
                $table->index(['ssl_check', 'ssl_expiry_date', 'id'], 'websites_ssl_check_expiry_id_idx');
            }

            if (! $this->indexExists('websites', 'websites_ssl_package_due_idx')) {
                $table->index(
                    ['ssl_check', 'source', 'uptime_check', 'package_interval', 'last_heartbeat_at', 'id'],
                    'websites_ssl_package_due_idx'
                );
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if ($this->indexExists('websites', 'websites_ssl_package_due_idx')) {
                $table->dropIndex('websites_ssl_package_due_idx');
            }

            if ($this->indexExists('websites', 'websites_ssl_check_expiry_id_idx')) {
                $table->dropIndex('websites_ssl_check_expiry_id_idx');
            }
        });
    }
};
