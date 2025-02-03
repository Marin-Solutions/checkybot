<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResponseBodyToMonitorApiResultsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->text('response_body')->nullable()->after('failed_assertions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->dropColumn('response_body');
        });
    }
}