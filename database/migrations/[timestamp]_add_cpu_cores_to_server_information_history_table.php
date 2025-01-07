<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_information_history', function (Blueprint $table) {
            $table->integer('cpu_cores')->nullable()->after('cpu_load');
        });
    }

    public function down(): void
    {
        Schema::table('server_information_history', function (Blueprint $table) {
            $table->dropColumn('cpu_cores');
        });
    }
}; 