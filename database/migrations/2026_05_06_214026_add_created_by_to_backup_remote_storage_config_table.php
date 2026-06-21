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

        DB::table('backup_remote_storage_config')
            ->orderBy('id')
            ->chunkById(100, function ($storages): void {
                $storages->each(fn (object $storage) => $this->scopeStorageToBackupOwners($storage));
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

    private function scopeStorageToBackupOwners(object $storage): void
    {
        $ownerIds = DB::table('backups')
            ->join('servers', 'servers.id', '=', 'backups.server_id')
            ->join('users', 'users.id', '=', 'servers.created_by')
            ->where('backups.remote_storage_id', $storage->id)
            ->whereNotNull('servers.created_by')
            ->distinct()
            ->orderBy('servers.created_by')
            ->pluck('servers.created_by')
            ->map(fn ($ownerId): int => (int) $ownerId)
            ->values();

        if ($ownerIds->isEmpty()) {
            return;
        }

        $existingOwnerId = $storage->created_by ? (int) $storage->created_by : null;
        $primaryOwnerId = $existingOwnerId && ! $ownerIds->contains($existingOwnerId)
            ? null
            : ($existingOwnerId ?: (int) $ownerIds->first());

        if ($primaryOwnerId !== null) {
            DB::table('backup_remote_storage_config')
                ->where('id', $storage->id)
                ->whereNull('created_by')
                ->update(['created_by' => $primaryOwnerId]);
        }

        $ownerIds
            ->reject(fn (int $ownerId): bool => $ownerId === $primaryOwnerId)
            ->each(function (int $ownerId) use ($storage): void {
                $storageCopyId = $this->copyStorageForOwner($storage, $ownerId);
                $this->moveOwnerBackupsToStorage((int) $storage->id, $storageCopyId, $ownerId);
            });
    }

    private function copyStorageForOwner(object $storage, int $ownerId): int
    {
        $attributes = (array) $storage;
        unset($attributes['id']);

        $attributes['created_by'] = $ownerId;

        return DB::table('backup_remote_storage_config')->insertGetId($attributes);
    }

    private function moveOwnerBackupsToStorage(int $fromStorageId, int $toStorageId, int $ownerId): void
    {
        DB::table('backups')
            ->where('remote_storage_id', $fromStorageId)
            ->whereIn('server_id', DB::table('servers')->select('id')->where('created_by', $ownerId))
            ->update(['remote_storage_id' => $toStorageId]);
    }
};
