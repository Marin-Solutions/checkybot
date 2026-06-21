<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('backup_remote_storage_config', 'created_by')) {
            Schema::table('backup_remote_storage_config', function (Blueprint $table) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('label')
                    ->constrained('users')
                    ->nullOnDelete();

                $table->index('created_by');
            });
        }

        DB::table('backups')
            ->join('servers', 'servers.id', '=', 'backups.server_id')
            ->selectRaw('backups.remote_storage_id, MIN(servers.created_by) as owner_id')
            ->groupBy('backups.remote_storage_id')
            ->havingRaw('COUNT(DISTINCT servers.created_by) = 1')
            ->orderBy('backups.remote_storage_id')
            ->lazy()
            ->each(function (object $storage): void {
                DB::table('backup_remote_storage_config')
                    ->where('id', $storage->remote_storage_id)
                    ->whereNull('created_by')
                    ->update(['created_by' => $storage->owner_id]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('backup_remote_storage_config', 'created_by')) {
            return;
        }

        Schema::table('backup_remote_storage_config', function (Blueprint $table) {
            try {
                $table->dropForeign(['created_by']);
            } catch (Throwable) {
                //
            }

            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
