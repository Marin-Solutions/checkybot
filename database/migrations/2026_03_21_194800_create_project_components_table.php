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
        Schema::create('project_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->default('package');
            $table->string('declared_interval', 50);
            $table->unsignedInteger('interval_minutes');
            $table->string('current_status', 20);
            $table->string('last_reported_status', 20);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('stale_detected_at')->nullable();
            $table->boolean('is_stale')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'is_archived', 'current_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_components');
    }
};
