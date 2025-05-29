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
        Schema::create('ploi_servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ploi_account_id');
            $table->unsignedBigInteger('server_id');
            $table->string('type');
            $table->string('name');
            $table->string('ip_address');
            $table->string('php_version');
            $table->string('mysql_version');
            $table->integer('sites_count')->default(0);
            $table->string('status');
            $table->integer('status_id');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ploi_servers');
    }
};
