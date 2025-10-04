<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            // Scope + inspection + active filtering (covers globalScope, websiteScope patterns)
            if (! $this->indexExists('notification_settings', 'idx_ns_scope_inspection')) {
                $table->index(['scope', 'inspection', 'flag_active'], 'idx_ns_scope_inspection');
            }

            // Channel type filtering for active notifications
            if (! $this->indexExists('notification_settings', 'idx_ns_channel_type')) {
                $table->index(['channel_type', 'flag_active'], 'idx_ns_channel_type');
            }

            // Foreign key to notification_channels
            if (! $this->indexExists('notification_settings', 'idx_ns_notification_channel_id')) {
                $table->index('notification_channel_id', 'idx_ns_notification_channel_id');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropIndex('idx_ns_scope_inspection');
            $table->dropIndex('idx_ns_channel_type');
            $table->dropIndex('idx_ns_notification_channel_id');
        });
    }
};
