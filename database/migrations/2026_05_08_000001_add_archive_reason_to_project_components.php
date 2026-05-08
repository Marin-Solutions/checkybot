<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('project_components', 'archive_reason')) {
            return;
        }

        Schema::table('project_components', function (Blueprint $table): void {
            $table->string('archive_reason', 20)->nullable()->after('archived_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('project_components', 'archive_reason')) {
            return;
        }

        Schema::table('project_components', function (Blueprint $table): void {
            $table->dropIndex(['archive_reason']);
            $table->dropColumn('archive_reason');
        });
    }
};
