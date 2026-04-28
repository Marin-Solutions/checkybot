<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedInteger('uptime_interval')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('websites')
            ->whereNull('uptime_interval')
            ->update(['uptime_interval' => 1]);

        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedInteger('uptime_interval')->nullable(false)->default(1)->change();
        });
    }
};
