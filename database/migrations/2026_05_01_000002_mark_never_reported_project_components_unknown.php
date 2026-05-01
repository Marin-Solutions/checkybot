<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_components')
            ->whereNull('last_heartbeat_at')
            ->where('current_status', 'healthy')
            ->update([
                'current_status' => 'unknown',
                'last_reported_status' => 'unknown',
                'summary' => 'Awaiting first heartbeat',
            ]);
    }

    public function down(): void
    {
        DB::table('project_components')
            ->whereNull('last_heartbeat_at')
            ->where('current_status', 'unknown')
            ->where('summary', 'Awaiting first heartbeat')
            ->update([
                'current_status' => 'healthy',
                'last_reported_status' => 'healthy',
                'summary' => null,
            ]);
    }
};
