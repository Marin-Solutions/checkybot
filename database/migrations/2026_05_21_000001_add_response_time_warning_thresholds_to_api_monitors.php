<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            if (! Schema::hasColumn('monitor_apis', 'max_response_time_ms')) {
                $table->unsignedInteger('max_response_time_ms')->nullable()->after('timeout_seconds');
            }
        });

        Schema::table('monitor_api_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('monitor_api_results', 'max_response_time_ms')) {
                $table->unsignedInteger('max_response_time_ms')->nullable()->after('response_time_ms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table): void {
            if (Schema::hasColumn('monitor_api_results', 'max_response_time_ms')) {
                $table->dropColumn('max_response_time_ms');
            }
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            if (Schema::hasColumn('monitor_apis', 'max_response_time_ms')) {
                $table->dropColumn('max_response_time_ms');
            }
        });
    }
};
