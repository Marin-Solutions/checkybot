<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->string('scope')->default('GLOBAL')->change();

            $table->foreignId('monitor_api_id')
                ->nullable()
                ->after('website_id')
                ->constrained('monitor_apis')
                ->cascadeOnDelete();

            $table->index(['monitor_api_id', 'scope', 'inspection', 'flag_active'], 'ns_api_monitor_scope_inspect_flag_idx');
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('notification_settings')
            ->where('scope', 'API_MONITOR')
            ->delete();

        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->dropIndex('ns_api_monitor_scope_inspect_flag_idx');
            $table->dropConstrainedForeignId('monitor_api_id');
            $table->enum('scope', ['GLOBAL', 'WEBSITE'])->default('GLOBAL')->change();
        });
    }
};
