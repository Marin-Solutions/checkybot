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
        // Add indexes to notification_settings table for performance
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->index(['user_id', 'scope', 'inspection', 'flag_active'], 'ns_user_scope_inspection_active');
            $table->index(['website_id', 'scope', 'inspection', 'flag_active'], 'ns_website_scope_inspection_active');
            $table->index('flag_active', 'ns_flag_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes from notification_settings table
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropIndex('ns_user_scope_inspection_active');
            $table->dropIndex('ns_website_scope_inspection_active');
            $table->dropIndex('ns_flag_active');
        });
    }
};
