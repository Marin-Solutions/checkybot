<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_information_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->onDelete('cascade');
            $table->decimal('cpu_load', 5, 2)->nullable();
            $table->decimal('ram_free_percentage', 5, 2)->nullable();
            $table->bigInteger('ram_free')->nullable();
            $table->decimal('disk_free_percentage', 5, 2)->nullable();
            $table->bigInteger('disk_free_bytes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_information_history');
    }
}; 