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
        // Add index on created_by for servers table if it doesn't exist
        if (! Schema::hasIndex('servers', 'servers_created_by_index')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->index('created_by');
            });
        }

        // Add composite index for servers table if it doesn't exist
        if (! Schema::hasIndex('servers', 'servers_created_by_deleted_at_index')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->index(['created_by', 'deleted_at']);
            });
        }

        // Add index on server_id for server_information_history table if it doesn't exist
        if (Schema::hasTable('server_information_history')) {
            if (! Schema::hasIndex('server_information_history', 'server_information_history_server_id_index')) {
                Schema::table('server_information_history', function (Blueprint $table) {
                    $table->index('server_id');
                });
            }

            // Add composite index for server_information_history table if it doesn't exist
            if (! Schema::hasIndex('server_information_history', 'server_information_history_server_id_created_at_index')) {
                Schema::table('server_information_history', function (Blueprint $table) {
                    $table->index(['server_id', 'created_at']);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['created_by', 'deleted_at']);
        });

        if (Schema::hasTable('server_information_history')) {
            Schema::table('server_information_history', function (Blueprint $table) {
                $table->dropIndex(['server_id']);
                $table->dropIndex(['server_id', 'created_at']);
            });
        }
    }
};
