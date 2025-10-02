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
            $table->index('created_by');
            $table->index(['created_by', 'created_at']);
            $table->index('uptime_check');
            $table->index('ssl_check');
            $table->index('outbound_check');
        });

        // Add indexes to website_log_history table
        Schema::table('website_log_history', function (Blueprint $table) {
            $table->index(['website_id', 'created_at']);
            $table->index('created_at');
            $table->index('speed');
        });

        // Add indexes to notification_settings table for performance
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->index(['user_id', 'scope', 'inspection', 'flag_active'], 'ns_user_scope_inspect_flag_idx');
            $table->index(['website_id', 'scope', 'inspection', 'flag_active'], 'ns_website_scope_inspect_flag_idx');
            $table->index('flag_active', 'ns_flag_active_idx');
        });
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
