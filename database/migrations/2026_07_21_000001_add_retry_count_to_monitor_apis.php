<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->unsignedTinyInteger('retry_count')->nullable()->after('timeout_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table): void {
            $table->dropColumn('retry_count');
        });
    }
};
