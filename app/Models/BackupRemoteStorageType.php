<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupRemoteStorageType extends Model
{
    use HasFactory;

    protected $table = 'backup_remote_storage_types';

    public function configs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BackupRemoteStorageConfig::class);
    }
}
