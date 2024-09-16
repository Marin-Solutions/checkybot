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
        Schema::table('server_information_histories', function (Blueprint $table) {
            $table->string('ram_free_percentage')->nullable();
            $table->string('ram_free')->nullable();
            $table->string('disk_free_percentage')->nullable();
            $table->string('disk_free_bytes')->nullable();
            $table->string('token')->unique()->nullable();
            $table->dropColumn('ram_user');
            $table->dropColumn('disk_use');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_information_histories', function (Blueprint $table) {
            $table->dropColumn('token');
            $table->dropColumn('ram_free_percentage');
            $table->dropColumn('ram_free');
            $table->dropColumn('disk_free_percentage');
            $table->dropColumn('disk_free_bytes');
            $table->string('ram_user')->nullable();
            $table->string('disk_use')->nullable();

        });
    }
};
