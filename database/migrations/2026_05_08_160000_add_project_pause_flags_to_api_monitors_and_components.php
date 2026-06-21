<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->boolean('project_paused_monitoring')->default(false)->after('is_enabled');
        });

        Schema::table('project_components', function (Blueprint $table): void {
            $table->boolean('project_paused_monitoring')->default(false)->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('project_components', function (Blueprint $table): void {
            $table->dropColumn('project_paused_monitoring');
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->dropColumn('project_paused_monitoring');
        });
    }
};
