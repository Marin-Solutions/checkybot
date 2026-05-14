<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('awaiting_heartbeat_since')->nullable()->after('last_heartbeat_at');
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->timestamp('awaiting_heartbeat_since')->nullable()->after('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('awaiting_heartbeat_since');
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->dropColumn('awaiting_heartbeat_since');
        });
    }
};
