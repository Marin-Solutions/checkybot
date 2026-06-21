<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table): void {
            $table->integer('effective_timeout_seconds')->nullable()->after('response_time_ms');
            $table->integer('retry_count')->nullable()->after('effective_timeout_seconds');
            $table->integer('elapsed_wall_time_ms')->nullable()->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table): void {
            $table->dropColumn([
                'effective_timeout_seconds',
                'retry_count',
                'elapsed_wall_time_ms',
            ]);
        });
    }
};
