<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupRemoteStorageType extends Model
{
    use HasFactory;

    public const DRIVER_FTP = 'ftp';

    public const DRIVER_SFTP = 'sftp';

    public const DRIVER_S3 = 's3';

    public const NAME_CUSTOM_S3 = 'Custom S3';

    protected $table = 'backup_remote_storage_types';

    public function configs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BackupRemoteStorageConfig::class);
    }

    public static function usesFileTransferFieldsForId(mixed $typeId): bool
    {
        return (bool) self::query()
            ->whereKey($typeId)
            ->whereIn('driver', [self::DRIVER_FTP, self::DRIVER_SFTP])
            ->exists();
    }

    public static function usesS3FieldsForId(mixed $typeId): bool
    {
        return (bool) self::query()
            ->whereKey($typeId)
            ->where('driver', self::DRIVER_S3)
            ->exists();
    }

    public static function requiresEndpointForId(mixed $typeId): bool
    {
        return (bool) self::query()
            ->whereKey($typeId)
            ->where('driver', self::DRIVER_S3)
            ->where('name', self::NAME_CUSTOM_S3)
            ->exists();
    }
}
