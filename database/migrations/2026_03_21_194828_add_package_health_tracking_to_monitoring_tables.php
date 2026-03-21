<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('current_status', 20)->nullable()->after('package_interval');
            $table->timestamp('last_heartbeat_at')->nullable()->after('current_status');
            $table->timestamp('stale_at')->nullable()->after('last_heartbeat_at');
            $table->string('status_summary')->nullable()->after('stale_at');
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('current_status', 20)->nullable()->after('package_interval');
            $table->timestamp('last_heartbeat_at')->nullable()->after('current_status');
            $table->timestamp('stale_at')->nullable()->after('last_heartbeat_at');
            $table->string('status_summary')->nullable()->after('stale_at');
        });

        Schema::table('website_log_history', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->after('speed');
            $table->string('summary')->nullable()->after('status');
        });

        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->after('response_body');
            $table->string('summary')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->dropColumn(['status', 'summary']);
        });

        Schema::table('website_log_history', function (Blueprint $table) {
            $table->dropColumn(['status', 'summary']);
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropColumn(['current_status', 'last_heartbeat_at', 'stale_at', 'status_summary']);
            $table->dropSoftDeletes();
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['current_status', 'last_heartbeat_at', 'stale_at', 'status_summary']);
        });
    }
};
