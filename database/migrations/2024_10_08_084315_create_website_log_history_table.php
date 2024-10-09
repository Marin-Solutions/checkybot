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
        Schema::create('website_log_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('website_id')->unsigned();
            $table->foreign('website_id')
                ->references('id')
                ->on('websites');
            $table->date('ssl_expiry_date')->nullable();
            $table->integer('http_status_code')->nullable();
            $table->integer('speed')->comment('in ms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_log_history');
    }
};
