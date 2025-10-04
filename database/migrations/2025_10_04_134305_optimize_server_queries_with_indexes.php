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
        Schema::table('server_information_history', function (Blueprint $table) {
            // Add composite index optimized for the subqueries
            // This covers: server_id + id DESC (for ORDER BY b.id DESC)
            if (! $this->indexExists('server_information_history', 'sih_server_id_id_desc_idx')) {
                $table->index(['server_id', 'id'], 'sih_server_id_id_desc_idx');
            }

            // Add composite index for server_id + created_at DESC
            // This helps with MAX(created_at) queries
            if (! $this->indexExists('server_information_history', 'sih_server_id_created_at_desc_idx')) {
                $table->index(['server_id', 'created_at'], 'sih_server_id_created_at_desc_idx');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_information_history', function (Blueprint $table) {
            $table->dropIndex('sih_server_id_id_desc_idx');
            $table->dropIndex('sih_server_id_created_at_desc_idx');
        });
    }
};
