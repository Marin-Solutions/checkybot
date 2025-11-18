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
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->string('inspection')->change();
        });

        \Illuminate\Support\Facades\DB::table('notification_settings')->whereIn('inspection', [
            'UPTIME_CHECK',
            'SSL_CHECK',
            'OUTBOUND_CHECK',
        ])->update(['inspection' => \App\Enums\WebsiteServicesEnum::WEBSITE_CHECK->name]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->enum('inspection', [
                'UPTIME_CHECK',
                'SSL_CHECK',
                'OUTBOUND_CHECK',
                'API_MONITOR',
                'ALL_CHECK',
            ])->change();
        });
    }
};
