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
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();
            $table->enum('scope', \App\Enums\NotificationScopesEnum::keys())->default(\App\Enums\NotificationScopesEnum::GLOBAL->name);
            $table->enum('inspection', \App\Enums\WebsiteServicesEnum::keys());
            $table->enum('channel_type', \App\Enums\NotificationChannelTypesEnum::keys())->default(\App\Enums\NotificationChannelTypesEnum::MAIL->name);
            $table->text('address');
            $table->text('data_path')->nullable();
            $table->boolean('flag_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
