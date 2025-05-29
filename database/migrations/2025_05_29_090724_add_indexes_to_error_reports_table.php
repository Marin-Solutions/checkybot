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
        Schema::table('error_reports', function (Blueprint $table) {
            // Add index on project_id for fast filtering
            $table->index('project_id', 'idx_error_reports_project_id');

            // Add index on exception_class for fast sorting
            $table->index('exception_class', 'idx_error_reports_exception_class');

            // Add composite index for the most common query pattern
            // This allows MySQL to filter by project_id AND sort by exception_class efficiently
            $table->index(['project_id', 'exception_class'], 'idx_error_reports_project_exception');

            // Add index on seen_at for time-based queries
            $table->index('seen_at', 'idx_error_reports_seen_at');

            // Add composite index for project + time-based queries
            $table->index(['project_id', 'seen_at'], 'idx_error_reports_project_seen_at');

            // Add index on created_at for general sorting
            $table->index('created_at', 'idx_error_reports_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('error_reports', function (Blueprint $table) {
            $table->dropIndex('idx_error_reports_project_id');
            $table->dropIndex('idx_error_reports_exception_class');
            $table->dropIndex('idx_error_reports_project_exception');
            $table->dropIndex('idx_error_reports_seen_at');
            $table->dropIndex('idx_error_reports_project_seen_at');
            $table->dropIndex('idx_error_reports_created_at');
        });
    }
};
