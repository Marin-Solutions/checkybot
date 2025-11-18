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
        // Check if token column exists before attempting to drop
        if (! Schema::hasColumn('server_information_history', 'token')) {
            return;
        }

        Schema::table('server_information_history', function (Blueprint $table) {
            // Drop unique index if it exists (SQLite requirement)
            if (Schema::hasIndex('server_information_history', 'server_information_history_token_unique')) {
                $table->dropUnique('server_information_history_token_unique');
            }

            $table->dropColumn('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_information_history', function (Blueprint $table) {
            //
        });
    }
};
