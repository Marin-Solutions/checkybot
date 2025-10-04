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
        // Add indexes to websites table
        Schema::table('websites', function (Blueprint $table) {
            if (! $this->indexExists('websites', 'websites_created_by_index')) {
                $table->index('created_by');
            }
            if (! $this->indexExists('websites', 'websites_created_by_created_at_index')) {
                $table->index(['created_by', 'created_at']);
            }
            if (! $this->indexExists('websites', 'websites_uptime_check_index')) {
                $table->index('uptime_check');
            }
            if (! $this->indexExists('websites', 'websites_ssl_check_index')) {
                $table->index('ssl_check');
            }
            if (! $this->indexExists('websites', 'websites_outbound_check_index')) {
                $table->index('outbound_check');
            }
        });

        // Add indexes to website_log_history table
        Schema::table('website_log_history', function (Blueprint $table) {
            if (! $this->indexExists('website_log_history', 'website_log_history_website_id_created_at_index')) {
                $table->index(['website_id', 'created_at']);
            }
            if (! $this->indexExists('website_log_history', 'website_log_history_created_at_index')) {
                $table->index('created_at');
            }
            if (! $this->indexExists('website_log_history', 'website_log_history_speed_index')) {
                $table->index('speed');
            }
        });

        // Add indexes to notification_settings table for performance
        Schema::table('notification_settings', function (Blueprint $table) {
            if (! $this->indexExists('notification_settings', 'ns_user_scope_inspect_flag_idx')) {
                $table->index(['user_id', 'scope', 'inspection', 'flag_active'], 'ns_user_scope_inspect_flag_idx');
            }
            if (! $this->indexExists('notification_settings', 'ns_website_scope_inspect_flag_idx')) {
                $table->index(['website_id', 'scope', 'inspection', 'flag_active'], 'ns_website_scope_inspect_flag_idx');
            }
            if (! $this->indexExists('notification_settings', 'ns_flag_active_idx')) {
                $table->index('flag_active', 'ns_flag_active_idx');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes from websites table
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['created_by', 'created_at']);
            $table->dropIndex(['uptime_check']);
            $table->dropIndex(['ssl_check']);
            $table->dropIndex(['outbound_check']);
        });

        // Remove indexes from website_log_history table
        Schema::table('website_log_history', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['speed']);
        });

        // Remove indexes from notification_settings table
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropIndex('ns_user_scope_inspect_flag_idx');
            $table->dropIndex('ns_website_scope_inspect_flag_idx');
            $table->dropIndex('ns_flag_active_idx');
        });
    }
};
