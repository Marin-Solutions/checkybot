<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitor_apis')) {
            return;
        }

        Schema::table('monitor_apis', function (Blueprint $table) {
            if (! Schema::hasColumn('monitor_apis', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitor_apis') || ! Schema::hasColumn('monitor_apis', 'deleted_at')) {
            return;
        }

        Schema::table('monitor_apis', function (Blueprint $table) {
            if (Schema::hasIndex('monitor_apis', 'monitor_apis_source_deleted_package_interval_idx')) {
                $table->dropIndex('monitor_apis_source_deleted_package_interval_idx');
            }

            if (Schema::hasIndex('monitor_apis', 'monitor_apis_created_by_deleted_at_idx')) {
                $table->dropIndex('monitor_apis_created_by_deleted_at_idx');
            }
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
