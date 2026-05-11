<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('last_component_synced_at')->nullable()->after('latest_package_sync_summary');
            $table->json('latest_component_sync_summary')->nullable()->after('last_component_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'last_component_synced_at',
                'latest_component_sync_summary',
            ]);
        });
    }
};
