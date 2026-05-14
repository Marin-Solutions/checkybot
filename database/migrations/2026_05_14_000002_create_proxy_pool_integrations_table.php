<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_pool_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_component_id')->nullable()->constrained('project_components')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('base_url');
            $table->text('token');
            $table->string('check_interval', 50)->default('5m');
            $table->boolean('is_active')->default(true);
            $table->string('last_sync_status', 20)->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'name']);
            $table->index(['created_by', 'is_active']);
            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_pool_integrations');
    }
};
