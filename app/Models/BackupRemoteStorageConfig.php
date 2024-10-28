<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Config;
    use Illuminate\Support\Facades\Storage;

    class BackupRemoteStorageConfig extends Model
    {
        use HasFactory;

        protected $table = 'backup_remote_storage_config';

        protected $fillable = [
            'backup_remote_storage_type_id',
            'label',
            'host',
            'port',
            'username',
            'password',
            'directory',
            'access_key',
            'secret_key',
            'bucket',
            'region',
            'endpoint'
        ];

        public function storageType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(BackupRemoteStorageType::class, 'backup_remote_storage_type_id');
        }

        public static function testConnection( $config ): array
        {
            $type       = BackupRemoteStorageType::query()->firstWhere('id', $config[ 'backup_remote_storage_type_id' ]);
            $testResult = [
                'error'   => false,
                'title'   => 'Test ' . $type->name . ' Connection',
                'message' => 'Successfully connected to ' . $config[ 'host' ] . '.',
            ];

            try {
                switch ( $type->driver ) {
                    case 'ftp':
                    case 'sftp':
                        config([
                            'filesystems.disks.temp_storage' => [
                                'driver'   => $type->driver,
                                'host'     => $config['host'],
                                'port'     => 21,
                                'username' => $config['username'],
                                'password' => $config['password'],
                                'root'     => $config['directory'] ?? '/',
                            ],
                        ]);
                        $connected = Storage::disk('temp_storage')->exists('/');
                        break;

                    case 's3':
                        config([
                            'filesystems.disks.temp_storage' => [
                                'driver'   => 's3',
                                'key'      => $config[ 'access_key' ],
                                'secret'   => $config[ 'secret_key' ],
                                'bucket'   => $config[ 'bucket' ],
                                'region'   => $config[ 'region' ],
                                'endpoint' => $config[ 'endpoint' ] ?? null,
                            ],
                        ]);
                        $connected = Storage::disk('temp_storage')->exists('');
                        break;

                    default:
                        throw new \Exception("Unsupported storage type: " . $type);
                }

                if ( !$connected ) {
                    $testResult[ 'error' ]   = true;
                    $testResult[ 'message' ] = 'Failed to connect to ' . $config[ 'host' ] . '.';
                }

                return $testResult;
            } catch ( \Exception $e ) {
                $testResult[ 'error' ]   = true;
                $testResult[ 'message' ] = 'Failed to connect to ' . $config[ 'host' ] . '. ' . $e->getMessage();

                return $testResult;
            }
        }
    }
