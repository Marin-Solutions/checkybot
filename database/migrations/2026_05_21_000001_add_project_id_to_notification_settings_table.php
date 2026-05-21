<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->foreignId('project_id')
                ->nullable()
                ->after('user_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->index(
                ['project_id', 'scope', 'inspection', 'flag_active'],
                'ns_project_scope_inspect_flag_idx'
            );
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('notification_settings')
            ->where('scope', 'PROJECT')
            ->delete();

        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->dropIndex('ns_project_scope_inspect_flag_idx');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
