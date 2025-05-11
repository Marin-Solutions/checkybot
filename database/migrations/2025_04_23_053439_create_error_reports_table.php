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
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('project_id');

            $table->string('notifier')->nullable();
            $table->string('language')->nullable();
            $table->string('framework_version')->nullable();
            $table->string('language_version')->nullable();
            $table->string('exception_class')->nullable();
            $table->timestamp('seen_at')->nullable(); // dari UNIX timestamp
            $table->text('message')->nullable();

            $table->json('glows')->nullable();
            $table->json('solutions')->nullable();
            $table->json('documentation_links')->nullable();
            $table->json('stacktrace')->nullable();
            $table->json('context')->nullable();

            $table->string('stage')->nullable();
            $table->string('message_level')->nullable();
            $table->unsignedInteger('open_frame_index')->nullable();
            $table->string('application_path')->nullable();
            $table->string('application_version')->nullable();
            $table->uuid('tracking_uuid')->nullable();
            $table->boolean('handled')->nullable();
            $table->string('overridden_grouping')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
