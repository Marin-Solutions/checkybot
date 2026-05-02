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
        Schema::table('project_components', function (Blueprint $table): void {
            $table->index(
                ['is_archived', 'is_stale', 'last_heartbeat_at', 'interval_minutes'],
                'project_components_stale_last_heartbeat_index'
            );

            $table->index(
                ['is_archived', 'is_stale', 'created_at', 'interval_minutes'],
                'project_components_stale_created_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_components', function (Blueprint $table): void {
            if (Schema::hasIndex('project_components', 'project_components_stale_last_heartbeat_index')) {
                $table->dropIndex('project_components_stale_last_heartbeat_index');
            }

            if (Schema::hasIndex('project_components', 'project_components_stale_created_index')) {
                $table->dropIndex('project_components_stale_created_index');
            }
        });
    }
};
