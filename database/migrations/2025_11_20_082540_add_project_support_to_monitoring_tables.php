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
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects')->cascadeOnDelete();
            $table->enum('source', ['manual', 'package'])->default('manual')->after('last_outbound_checked_at');
            $table->string('package_name')->nullable()->after('source');
            $table->string('package_interval', 50)->nullable()->after('package_name');

            $table->index(['project_id', 'source', 'package_name'], 'idx_websites_project_source_name');
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects')->cascadeOnDelete();
            $table->enum('source', ['manual', 'package'])->default('manual')->after('created_by');
            $table->string('package_name')->nullable()->after('source');
            $table->string('package_interval', 50)->nullable()->after('package_name');

            $table->index(['project_id', 'source', 'package_name'], 'idx_monitor_apis_project_source_name');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex('idx_websites_project_source_name');
            $table->dropForeign(['project_id']);
            $table->dropColumn(['project_id', 'source', 'package_name', 'package_interval']);
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropIndex('idx_monitor_apis_project_source_name');
            $table->dropForeign(['project_id']);
            $table->dropColumn(['project_id', 'source', 'package_name', 'package_interval']);
        });
    }
};
