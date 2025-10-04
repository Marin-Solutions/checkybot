<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_information_history', function (Blueprint $table) {
            // Soft delete filtering
            if (! $this->indexExists('server_information_history', 'idx_sih_deleted_at')) {
                $table->index('deleted_at', 'idx_sih_deleted_at');
            }

            // Server + created_at + deleted_at (filtering active records for a server ordered by date)
            if (! $this->indexExists('server_information_history', 'idx_sih_server_created_deleted')) {
                $table->index(['server_id', 'created_at', 'deleted_at'], 'idx_sih_server_created_deleted');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('server_information_history', function (Blueprint $table) {
            $table->dropIndex('idx_sih_deleted_at');
            $table->dropIndex('idx_sih_server_created_deleted');
        });
    }
};
