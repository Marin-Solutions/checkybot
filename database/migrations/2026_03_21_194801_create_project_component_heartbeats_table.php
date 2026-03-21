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
        Schema::create('project_component_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_component_id')->constrained('project_components')->cascadeOnDelete();
            $table->string('component_name');
            $table->string('status', 20);
            $table->string('event', 20);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['project_component_id', 'observed_at']);
            $table->index(['component_name', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_component_heartbeats');
    }
};
