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
            try {
                $table->index('created_by');
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index(['created_by', 'created_at']);
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index('uptime_check');
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index('ssl_check');
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index('outbound_check');
            } catch (\Exception $e) {
                // Index already exists
            }
        });

        // Add indexes to website_log_history table
        Schema::table('website_log_history', function (Blueprint $table) {
            try {
                $table->index(['website_id', 'created_at']);
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index('created_at');
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index('speed');
            } catch (\Exception $e) {
                // Index already exists
            }
        });

        // Add indexes to notification_settings table for performance
        Schema::table('notification_settings', function (Blueprint $table) {
            try {
                $table->index(['user_id', 'scope', 'inspection', 'flag_active']);
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index(['website_id', 'scope', 'inspection', 'flag_active']);
            } catch (\Exception $e) {
                // Index already exists
            }
            try {
                $table->index('flag_active');
            } catch (\Exception $e) {
                // Index already exists
            }
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
            $table->dropIndex(['user_id', 'scope', 'inspection', 'flag_active']);
            $table->dropIndex(['website_id', 'scope', 'inspection', 'flag_active']);
            $table->dropIndex(['flag_active']);
        });
    }
};
