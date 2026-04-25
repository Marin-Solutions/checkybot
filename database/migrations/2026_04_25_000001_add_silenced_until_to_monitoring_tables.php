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
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->timestamp('silenced_until')->nullable()->after('status_summary');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->dropColumn('silenced_until');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('silenced_until');
        });
    }
};
