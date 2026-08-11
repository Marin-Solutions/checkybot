<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OBSERVED_AT_INDEX = 'pch_component_observed_idx';

    private const IDEMPOTENCY_KEY_INDEX = 'pch_component_idempotency_uniq';

    public function up(): void
    {
        if (! Schema::hasTable('project_component_heartbeats')) {
            return;
        }

        if (! Schema::hasIndex('project_component_heartbeats', ['project_component_id', 'observed_at'])) {
            Schema::table('project_component_heartbeats', function (Blueprint $table): void {
                $table->index(['project_component_id', 'observed_at'], self::OBSERVED_AT_INDEX);
            });
        }

        if (! $this->hasUniqueIdempotencyIndex()) {
            Schema::table('project_component_heartbeats', function (Blueprint $table): void {
                $table->unique(['project_component_id', 'idempotency_key'], self::IDEMPOTENCY_KEY_INDEX);
            });
        }
    }

    public function down(): void {}

    private function hasUniqueIdempotencyIndex(): bool
    {
        foreach (Schema::getIndexes('project_component_heartbeats') as $index) {
            if ($index['columns'] === ['project_component_id', 'idempotency_key'] && $index['unique']) {
                return true;
            }
        }

        return false;
    }
};
