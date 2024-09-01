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
        Schema::create('server_information_histories', function (Blueprint $table) {
            $table->id();
            $table->string('ram_user');
            $table->string('disk_use');
            $table->decimal('cpu_load', total: 4, places: 2);
            $table->bigInteger('server_id')->unsigned();
            $table->foreign('server_id')
                ->references('id')
                ->on('servers');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_information_histories');
    }
};
