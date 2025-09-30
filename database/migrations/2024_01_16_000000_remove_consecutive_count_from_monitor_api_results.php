<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->dropColumn('consecutive_count');
        });
    }

    public function down()
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->integer('consecutive_count')->after('is_success')->default(1);
        });
    }
};
