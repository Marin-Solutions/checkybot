<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seo_schedules', function (Blueprint $table) {
            $table->time('schedule_time')->default('02:00:00')->after('frequency');
            $table->string('schedule_day')->nullable()->after('schedule_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_schedules', function (Blueprint $table) {
            $table->dropColumn(['schedule_time', 'schedule_day']);
        });
    }
};
