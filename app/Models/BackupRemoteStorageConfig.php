<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BackupRemoteStorageConfig extends Model
{
    use HasFactory;

    protected $table = 'backup_remote_storage_config';

    protected $fillable = [
        'backup_remote_storage_type_id',
        'label',
        'created_by',
        'host',
        'port',
        'username',
        'password',
        'directory',
        'access_key',
        'secret_key',
        'bucket',
        'region',
        'endpoint',
    ];

    protected $casts = [
        'created_by' => 'integer',
    ];

    public function storageType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BackupRemoteStorageType::class, 'backup_remote_storage_type_id');
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    public static function testConnection($config): array
    {
        $type = BackupRemoteStorageType::query()->firstWhere('id', $config['backup_remote_storage_type_id']);
        $target = $config['host'] ?? $config['endpoint'] ?? $config['bucket'] ?? $type->name;
        $port = filled($config['port'] ?? null) ? (int) $config['port'] : 21;
        $testResult = [
            'error' => false,
            'title' => 'Test '.$type->name.' Connection',
            'message' => 'Successfully connected to '.$target.'.',
        ];

        try {
            Storage::forgetDisk('temp_storage');

            switch ($type->driver) {
                case 'ftp':
                case 'sftp':
                    config([
                        'filesystems.disks.temp_storage' => [
                            'driver' => $type->driver,
                            'host' => $config['host'],
                            'port' => $port,
                            'username' => $config['username'],
                            'password' => $config['password'],
                            'root' => $config['directory'] ?? '/',
                        ],
                    ]);
                    $connected = Storage::disk('temp_storage')->exists('/');
                    break;

                case 's3':
                    config([
                        'filesystems.disks.temp_storage' => [
                            'driver' => 's3',
                            'key' => $config['access_key'],
                            'secret' => $config['secret_key'],
                            'bucket' => $config['bucket'],
                            'region' => $config['region'],
                            'endpoint' => $config['endpoint'] ?? null,
                        ],
                    ]);
                    $connected = Storage::disk('temp_storage')->exists('');
                    break;

                default:
                    throw new \Exception('Unsupported storage type: '.$type);
            }

            if (! $connected) {
                $testResult['error'] = true;
                $testResult['message'] = 'Failed to connect to '.$target.'.';
            }

            return $testResult;
        } catch (\Exception $e) {
            $testResult['error'] = true;
            $testResult['message'] = 'Failed to connect to '.$target.'. '.$e->getMessage();

            return $testResult;
        }
    }
}
