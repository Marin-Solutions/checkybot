<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_components', 'status_observed_at')) {
            Schema::table('project_components', function (Blueprint $table): void {
                $table->timestamp('status_observed_at')->nullable()->after('last_reported_status');
            });
        }

        if (Schema::hasTable('project_component_heartbeats')) {
            if (! Schema::hasColumn('project_component_heartbeats', 'idempotency_key')) {
                Schema::table('project_component_heartbeats', function (Blueprint $table): void {
                    $table->string('idempotency_key', 64)->nullable();
                    $table->unique(['project_component_id', 'idempotency_key']);
                });
            }

            return;
        }

        Schema::create('project_component_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_component_id')->constrained('project_components')->cascadeOnDelete();
            $table->string('component_name', 64);
            $table->string('status', 20);
            $table->string('event', 20);
            $table->text('summary');
            $table->json('metrics')->nullable();
            $table->timestamp('observed_at');
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->index(['project_component_id', 'observed_at']);
            $table->unique(['project_component_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_component_heartbeats');

        if (Schema::hasColumn('project_components', 'status_observed_at')) {
            Schema::table('project_components', function (Blueprint $table): void {
                $table->dropColumn('status_observed_at');
            });
        }
    }
};
