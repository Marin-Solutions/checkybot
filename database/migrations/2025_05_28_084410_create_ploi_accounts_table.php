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
        Schema::create('ploi_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedBigInteger('created_by');
            $table->text('key')->comment('Ploi API Key');
            $table->boolean('is_verified')->default(false);
            $table->string('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ploi_accounts');
    }
};
