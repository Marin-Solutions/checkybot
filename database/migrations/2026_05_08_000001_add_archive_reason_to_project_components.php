<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_components', 'archive_reason')) {
            Schema::table('project_components', function (Blueprint $table): void {
                $table->string('archive_reason', 20)->nullable()->after('archived_at');
            });
        }

        if (! Schema::hasIndex('project_components', 'project_components_archive_reason_index')) {
            Schema::table('project_components', function (Blueprint $table): void {
                $table->index('archive_reason');
            });
        }

        DB::table('project_components')
            ->where('is_archived', true)
            ->whereNull('archive_reason')
            ->update(['archive_reason' => 'user']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('project_components', 'archive_reason')) {
            return;
        }

        Schema::table('project_components', function (Blueprint $table): void {
            if (Schema::hasIndex('project_components', 'project_components_archive_reason_index')) {
                $table->dropIndex(['archive_reason']);
            }

            $table->dropColumn('archive_reason');
        });
    }
};
