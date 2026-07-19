<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->unsignedTinyInteger('health_summary_interval_minutes')->nullable()->after('description');
            $table->timestamp('health_summary_last_attempted_at')->nullable()->after('health_summary_interval_minutes');
            $table->index('health_summary_interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->dropIndex(['health_summary_interval_minutes']);
            $table->dropColumn([
                'health_summary_interval_minutes',
                'health_summary_last_attempted_at',
            ]);
        });
    }
};
