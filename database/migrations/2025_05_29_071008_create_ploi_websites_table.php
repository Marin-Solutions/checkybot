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
        Schema::create('ploi_websites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ploi_account_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('server_id')->nullable()->comment('The ID of the Ploi server');
            $table->string('domain')->nullable();
            $table->text('deploy_script')->nullable();
            $table->string('web_directory')->nullable();
            $table->string('project_type')->nullable();
            $table->string('project_root')->nullable();
            $table->timestamp('last_deploy_at')->nullable();
            $table->string('system_user')->nullable();
            $table->string('php_version')->nullable();
            $table->string('health_url')->nullable();
            $table->json('notification_urls')->nullable();
            $table->boolean('has_repository')->default(false);
            $table->timestamp('site_created_at')->nullable()->comment('The date when the site was created on Ploi');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ploi_websites');
    }
};
