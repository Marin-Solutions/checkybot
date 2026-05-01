<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('project_paused_uptime_check')->default(false)->after('uptime_check');
            $table->boolean('project_paused_ssl_check')->default(false)->after('ssl_check');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn([
                'project_paused_uptime_check',
                'project_paused_ssl_check',
            ]);
        });
    }
};
