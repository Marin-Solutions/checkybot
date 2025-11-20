<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

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
        $user = Auth::user()->id;
        $backupId = $backup->id;
        $backupInterval = $backup->interval->expression;
        $scriptFileName = 'backup_folder.sh';
        $scriptFileNameInit = 'init_'.$scriptFileName;
        $backupIntervalInit = $backup->generateCronExpression();
        $serverId = $backup->server_id;

        $command = "wget https://checkybot.com/backup/$backupId/$serverId/$user/0 -O $scriptFileName ";
        $command .= "&& chmod +x $(pwd)/$scriptFileName ";
        $command .= "&& CRON_CMD=\"$(pwd)/$scriptFileName\" ";
        $command .= "&& (crontab -l | grep -Fq \"$backupInterval \$CRON_CMD\" || ";
        $command .= "(crontab -l 2>/dev/null; echo \"$backupInterval \$CRON_CMD\") | crontab -) ";
        // first run cron
        $command .= "&& wget https://checkybot.com/init-backup/$backupId/$serverId/$user/1 -O $scriptFileNameInit ";
        $command .= "&& chmod +x $(pwd)/$scriptFileNameInit ";
        $command .= "&& CRON_CMD=\"$(pwd)/$scriptFileNameInit\" ";
        $command .= "&& (crontab -l | grep -Fq \"$backupIntervalInit \$CRON_CMD\" || ";
        $command .= "(crontab -l 2>/dev/null; echo \"$backupIntervalInit \$CRON_CMD\") | crontab -)";

        return $command;
    }

    public static function doShellScript(int $backup_id, int $server_id, int $user, int $init): Response
    {
        $backup = Backup::query()->where('id', $backup_id)->first();

        if ($backup && $backup->server_id == $server_id && $backup->server->created_by == $user) {
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
        $ftpHost = escapeshellarg($this->remoteStorage->host);
        $ftpUser = escapeshellarg($this->remoteStorage->username);
        $ftpPass = '"'.$this->remoteStorage->password.'"';
        $ftpFolder = rtrim($this->remote_storage_path, '/');
        $fileName = $this->backup_filename ?? $folder;
        $url = $_ENV['APP_URL'];
        $serverId = $this->server->id;
        $token = $this->server->token;
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

        $uploadCmd = "curl -s -T \${FILE_NAME} -u $ftpUser:$ftpPass ftp://$ftpHost/$ftpFolder/";

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
