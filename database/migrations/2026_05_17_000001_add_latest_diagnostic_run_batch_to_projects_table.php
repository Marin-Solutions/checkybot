<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('latest_diagnostic_run_batch_id')->nullable()->after('latest_component_sync_summary');
            $table->timestamp('latest_diagnostic_run_batch_queued_at')->nullable()->after('latest_diagnostic_run_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'latest_diagnostic_run_batch_id',
                'latest_diagnostic_run_batch_queued_at',
            ]);
        });
    }
};
