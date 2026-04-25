<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('silenced_until')->nullable()->after('status_summary');
            // ProcessExpiredSnoozes runs every minute and filters on this
            // column; without an index that is a repeated full-table scan.
            $table->index('silenced_until');
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->timestamp('silenced_until')->nullable()->after('status_summary');
            $table->index('silenced_until');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->dropIndex(['silenced_until']);
            $table->dropColumn('silenced_until');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex(['silenced_until']);
            $table->dropColumn('silenced_until');
        });
    }
};
