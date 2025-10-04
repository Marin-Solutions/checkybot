<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add index on created_by for servers table if it doesn't exist
        $indexExists = DB::select("SHOW INDEX FROM servers WHERE Key_name = 'servers_created_by_index'");
        if (empty($indexExists)) {
            Schema::table('servers', function (Blueprint $table) {
                $table->index('created_by');
            });
        }

        // Add composite index for servers table if it doesn't exist
        $compositeIndexExists = DB::select("SHOW INDEX FROM servers WHERE Key_name = 'servers_created_by_deleted_at_index'");
        if (empty($compositeIndexExists)) {
            Schema::table('servers', function (Blueprint $table) {
                $table->index(['created_by', 'deleted_at']);
            });
        }

        // Add index on server_id for server_information_history table if it doesn't exist
        if (Schema::hasTable('server_information_history')) {
            $historyIndexExists = DB::select("SHOW INDEX FROM server_information_history WHERE Key_name = 'server_information_history_server_id_index'");
            if (empty($historyIndexExists)) {
                Schema::table('server_information_history', function (Blueprint $table) {
                    $table->index('server_id');
                });
            }

            // Add composite index for server_information_history table if it doesn't exist
            $historyCompositeIndexExists = DB::select("SHOW INDEX FROM server_information_history WHERE Key_name = 'server_information_history_server_id_created_at_index'");
            if (empty($historyCompositeIndexExists)) {
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
