<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('latest_scheduled_result_at')->nullable()->after('diagnostic_queued_at');
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->timestamp('latest_scheduled_result_at')->nullable()->after('diagnostic_queued_at');
        });

        $this->backfill(
            historyTable: 'website_log_history',
            foreignKey: 'website_id',
            parentTable: 'websites',
        );

        $this->backfill(
            historyTable: 'monitor_api_results',
            foreignKey: 'monitor_api_id',
            parentTable: 'monitor_apis',
        );
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('latest_scheduled_result_at');
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->dropColumn('latest_scheduled_result_at');
        });
    }

    private function backfill(string $historyTable, string $foreignKey, string $parentTable): void
    {
        DB::table($historyTable)
            ->selectRaw("{$foreignKey}, MAX(created_at) as latest_scheduled_result_at")
            ->where(function ($query): void {
                $query
                    ->whereNull('is_on_demand')
                    ->orWhere('is_on_demand', false);
            })
            ->groupBy($foreignKey)
            ->lazyById(500, column: $foreignKey)
            ->each(function (object $result) use ($foreignKey, $parentTable): void {
                DB::table($parentTable)
                    ->where('id', $result->{$foreignKey})
                    ->where(function ($query) use ($result): void {
                        $query
                            ->whereNull('latest_scheduled_result_at')
                            ->orWhere('latest_scheduled_result_at', '<', $result->latest_scheduled_result_at);
                    })
                    ->update([
                        'latest_scheduled_result_at' => $result->latest_scheduled_result_at,
                    ]);
            });
    }
};
