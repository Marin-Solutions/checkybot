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
        Schema::table('monitor_apis', function (Blueprint $table): void {
            if (! Schema::hasColumn('monitor_apis', 'project_component_id')) {
                $table->foreignId('project_component_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('project_components')
                    ->nullOnDelete();
            }
        });

        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'project_component_id')) {
                $table->foreignId('project_component_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('project_components')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            if (Schema::hasColumn('monitor_apis', 'project_component_id')) {
                $table->dropConstrainedForeignId('project_component_id');
            }
        });

        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasColumn('websites', 'project_component_id')) {
                $table->dropConstrainedForeignId('project_component_id');
            }
        });
    }
};
