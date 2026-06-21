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
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'last_reporter_ip')) {
                $table->string('last_reporter_ip', 45)->nullable();
            }

            if (! Schema::hasColumn('servers', 'last_reporter_user_agent')) {
                $table->text('last_reporter_user_agent')->nullable();
            }

            if (! Schema::hasColumn('servers', 'last_reporter_seen_at')) {
                $table->timestamp('last_reporter_seen_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'last_reporter_seen_at')) {
                $table->dropColumn('last_reporter_seen_at');
            }

            if (Schema::hasColumn('servers', 'last_reporter_user_agent')) {
                $table->dropColumn('last_reporter_user_agent');
            }

            if (Schema::hasColumn('servers', 'last_reporter_ip')) {
                $table->dropColumn('last_reporter_ip');
            }
        });
    }
};
