<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class Backup extends Model
{
    use HasFactory;

    protected $table = 'backups';

    protected $fillable = [
        'server_id',
        'dir_path',
        'remote_storage_id',
        'remote_storage_path',
        'interval_id',
        'first_run_at',
        'max_amount_backups',
        'exclude_folder_files',
        'password',
        'backup_filename',
        'compression_type',
        'delete_local_on_fail',
    ];

    protected $casts = [
        'delete_local_on_fail' => 'boolean',
        'first_run_at' => 'datetime',
    ];

    public function server(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id', 'id');
    }

    public function remoteStorage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BackupRemoteStorageConfig::class, 'remote_storage_id', 'id');
    }

    public function interval(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BackupIntervalOption::class, 'interval_id', 'id');
    }

    public static function copyCommand(Backup $backup): string
    {
        $user = Auth::id();
        if (! $user) {
            return '';
        }

        $backupId = $backup->id;
        $backupInterval = $backup->interval?->expression ?? '* * * * *';
        $scriptFileName = 'backup_folder.sh';
        $scriptFileNameInit = 'init_'.$scriptFileName;
        $backupIntervalInit = $backup->generateCronExpression();
        $serverId = $backup->server_id;

        $backupScriptUrl = URL::temporarySignedRoute('backup.script.download', now()->addHours(24), [
            'backup_id' => $backupId,
            'server_id' => $serverId,
            'user' => $user,
            'init' => 0,
        ]);

        $backupScriptInitUrl = URL::temporarySignedRoute('backup.script.download', now()->addHours(24), [
            'backup_id' => $backupId,
            'server_id' => $serverId,
            'user' => $user,
            'init' => 1,
        ]);

        $command = 'wget '.escapeshellarg($backupScriptUrl)." -O $scriptFileName ";
        $command .= "&& chmod +x $(pwd)/$scriptFileName ";
        $command .= "&& CRON_CMD=\"$(pwd)/$scriptFileName\" ";
        $command .= "&& (crontab -l | grep -Fq \"$backupInterval \$CRON_CMD\" || ";
        $command .= "(crontab -l 2>/dev/null; echo \"$backupInterval \$CRON_CMD\") | crontab -) ";
        // first run cron
        $command .= '&& wget '.escapeshellarg($backupScriptInitUrl)." -O $scriptFileNameInit ";
        $command .= "&& chmod +x $(pwd)/$scriptFileNameInit ";
        $command .= "&& CRON_CMD=\"$(pwd)/$scriptFileNameInit\" ";
        $command .= "&& (crontab -l | grep -Fq \"$backupIntervalInit \$CRON_CMD\" || ";
        $command .= "(crontab -l 2>/dev/null; echo \"$backupIntervalInit \$CRON_CMD\") | crontab -)";

        return $command;
    }

    public static function doShellScript(int $backup_id, int $server_id, int $user, int $init): Response
    {
        $backup = Backup::query()->where('id', $backup_id)->first();

        if ($backup && $backup->server_id == $server_id && $backup->server?->created_by == $user) {
            $content = $backup->backupScript($init);
            $status = 200;
        } else {
            $content = 'Error: Server owner mismatch. Please try with your server or ensure a backup configuration exists for this server. Thank you.';
            $status = 404;
        }

        $response = response()->make($content, $status);
        $response->header('Content-Type', 'application/x-sh');
        $response->header('Content-Disposition', 'attachment; filename="backup_folder.sh"');

        return $response;
    }

    public function backupScript($init = 0): string
    {
        $folder = rtrim($this->dir_path, '/');
        $format = $this->compression_type;
        $password = escapeshellarg($this->password);
        $exclude = $this->exclude_folder_files ?? '';
        $fileName = $this->backup_filename ?? $folder;
        $url = config('app.url');
        $serverId = $this->server?->id ?? $this->server_id;
        $token = $this->server?->token ?? '';
        $apiLogBackup = "$url/api/v1/backup-history";
        $firstRunAt = strtotime($this->first_run_at ?? now());

        $excludeArgs = '';
        if (! empty($exclude)) {
            $patterns = explode(',', $exclude);
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if ($format === 'zip') {
                    $excludeArgs .= " -xr'!$pattern'";
                } else {
                    $excludeArgs .= " --exclude='$pattern'";
                }
            }
        }

        if ($format === 'zip') {
            $compressCmd = "7z a -p$password \${FILE_NAME} '$folder/*'$excludeArgs";
        } else {
            $compressCmd = "tar -czvf \${FILE_NAME} $folder$excludeArgs";
        }

        $uploadCmd = $this->backupUploadCommand();

        $firstRunAtIfStart = '';
        $firstRunAtIfEnd = '';
        if ($init == 0 && $this->first_run_at) {
            $firstRunAtIfStart = "if [ \$(date +%s) -gt $firstRunAt ]; then";
            $firstRunAtIfEnd = 'fi';
        }

        return <<<BASH
#!/bin/bash
$firstRunAtIfStart
TIMESTAMP=\$(date +"%Y%m%d_%H%M%S")
FILE_NAME="{$fileName}_\${TIMESTAMP}.$format"
DO_ZIP="$($compressCmd 2>&1)"
DO_ZIP_STATUS=$?

if [ \$DO_ZIP_STATUS -eq 0 ]; then
    IS_ZIPPED=1
    FILE_SIZE=$(stat -c%s "\${FILE_NAME}")

    DO_UPLOAD_FILE="$($uploadCmd 2>&1)"
    UPLOAD_FILE_STATUS=$?
    if [ \$UPLOAD_FILE_STATUS -eq 0 ]; then
        IS_UPLOADED=1
    else
        IS_UPLOADED=0
    fi
else
    IS_ZIPPED=0
fi

curl -X POST $apiLogBackup \
 -H "Authorization: Bearer $token" \
 -H "Content-Type: application/json" \
 -H "Accept: application/json" \
 -d "{\"bi\": $serverId, \"iz\": \$IS_ZIPPED, \"iu\": \$IS_UPLOADED, \"sf\": \$FILE_SIZE, \"nf\": \"\$FILE_NAME\"}"
$firstRunAtIfEnd
BASH;
    }

    protected function backupUploadCommand(): string
    {
        $remoteStorage = $this->remoteStorage;
        $driver = $remoteStorage?->storageType?->driver;

        return match ($driver) {
            'ftp', 'sftp' => $this->ftpUploadCommand($driver),
            's3' => $this->s3UploadCommand(),
            default => 'echo '.escapeshellarg('Unsupported backup remote storage driver: '.($driver ?? 'missing')).' && false',
        };
    }

    protected function ftpUploadCommand(string $driver): string
    {
        $remoteStorage = $this->remoteStorage;
        $host = $remoteStorage?->host ?? '';
        $port = $remoteStorage?->port;
        $credentials = escapeshellarg(($remoteStorage?->username ?? '').':'.($remoteStorage?->password ?? ''));
        $remotePath = $this->joinRemotePath($remoteStorage?->directory, $this->remote_storage_path);
        $portSegment = $port ? ':'.$port : '';
        $targetUrl = escapeshellarg("{$driver}://{$host}{$portSegment}/{$remotePath}/");

        return "curl -sS --fail -T \${FILE_NAME} --user $credentials $targetUrl";
    }

    protected function s3UploadCommand(): string
    {
        $remoteStorage = $this->remoteStorage;
        $remotePath = $this->joinRemotePath($this->remote_storage_path);
        $targetPrefix = 's3://'.trim($remoteStorage?->bucket ?? '', '/').($remotePath !== '' ? '/'.$remotePath : '').'/';
        $endpoint = $remoteStorage?->endpoint ? ' --endpoint-url '.escapeshellarg($remoteStorage->endpoint) : '';

        return 'AWS_ACCESS_KEY_ID='.escapeshellarg($remoteStorage?->access_key ?? '')
            .' AWS_SECRET_ACCESS_KEY='.escapeshellarg($remoteStorage?->secret_key ?? '')
            .' AWS_DEFAULT_REGION='.escapeshellarg($remoteStorage?->region ?? '')
            .' aws s3 cp "${FILE_NAME}" '.escapeshellarg($targetPrefix).'"${FILE_NAME}" --only-show-errors'.$endpoint;
    }

    protected function joinRemotePath(?string ...$paths): string
    {
        return collect($paths)
            ->filter(fn (?string $path): bool => filled($path))
            ->map(fn (string $path): string => trim($path, '/'))
            ->filter()
            ->implode('/');
    }

    public function generateCronExpression(): ?string
    {
        if ($this->first_run_at) {

            $minute = (int) $this->first_run_at->format('i');
            $hour = (int) $this->first_run_at->format('G');
            $day = (int) $this->first_run_at->format('j');
            $month = (int) $this->first_run_at->format('n');
            $dayOfWeek = (int) $this->first_run_at->format('w');

            return sprintf('%d %d %d %d %d', $minute, $hour, $day, $month, $dayOfWeek);
        } else {
            return '* * * * *';
        }
    }
}
