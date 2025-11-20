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
        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->boolean('save_failed_response')->default(true)->after('headers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropColumn('save_failed_response');
        });
    }
};
