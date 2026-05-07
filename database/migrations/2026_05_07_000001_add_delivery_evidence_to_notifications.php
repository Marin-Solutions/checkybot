<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->string('last_delivery_kind')->nullable()->after('flag_active');
            $table->boolean('last_delivery_succeeded')->nullable()->after('last_delivery_kind');
            $table->unsignedSmallInteger('last_delivery_response_code')->nullable()->after('last_delivery_succeeded');
            $table->string('last_delivery_summary', 500)->nullable()->after('last_delivery_response_code');
            $table->timestamp('last_delivery_attempted_at')->nullable()->after('last_delivery_summary');
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('last_delivery_kind')->nullable()->after('created_by');
            $table->boolean('last_delivery_succeeded')->nullable()->after('last_delivery_kind');
            $table->unsignedSmallInteger('last_delivery_response_code')->nullable()->after('last_delivery_succeeded');
            $table->string('last_delivery_summary', 500)->nullable()->after('last_delivery_response_code');
            $table->timestamp('last_delivery_attempted_at')->nullable()->after('last_delivery_summary');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'last_delivery_kind',
                'last_delivery_succeeded',
                'last_delivery_response_code',
                'last_delivery_summary',
                'last_delivery_attempted_at',
            ]);
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn([
                'last_delivery_kind',
                'last_delivery_succeeded',
                'last_delivery_response_code',
                'last_delivery_summary',
                'last_delivery_attempted_at',
            ]);
        });
    }
};
