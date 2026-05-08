<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_remote_storage_config', function (Blueprint $table) {
            $table->unsignedInteger('created_by')->nullable()->index();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->unsignedInteger('created_by')->nullable()->index();
        });

        DB::table('backups')
            ->select(['id', 'server_id'])
            ->orderBy('id')
            ->chunkById(100, function ($backups): void {
                $serverOwners = DB::table('servers')
                    ->whereIn('id', $backups->pluck('server_id')->filter()->unique()->values())
                    ->pluck('created_by', 'id');

                foreach ($backups as $backup) {
                    $ownerId = $serverOwners[$backup->server_id] ?? null;

                    if ($ownerId !== null) {
                        DB::table('backups')
                            ->where('id', $backup->id)
                            ->update(['created_by' => $ownerId]);
                    }
                }
            });

        DB::table('backup_remote_storage_config')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($storages): void {
                foreach ($storages as $storage) {
                    $ownerIds = DB::table('backups')
                        ->where('remote_storage_id', $storage->id)
                        ->whereNotNull('created_by')
                        ->distinct()
                        ->pluck('created_by');

                    if ($ownerIds->count() === 1) {
                        DB::table('backup_remote_storage_config')
                            ->where('id', $storage->id)
                            ->update(['created_by' => $ownerIds->first()]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });

        Schema::table('backup_remote_storage_config', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
