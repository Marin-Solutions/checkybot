<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class Backup extends Model
{
    use HasFactory;

    protected $table = 'backups';

    protected $fillable = [
        'server_id',
        'created_by',
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
        'created_by' => 'integer',
        'delete_local_on_fail' => 'boolean',
        'first_run_at' => 'datetime',
        'last_history_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    public function server(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id', 'id');
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOwnedBy($query, int $userId)
    {
        return $query
            ->where('created_by', $userId)
            ->whereHas('server', fn ($serverQuery) => $serverQuery->where('created_by', $userId));
    }

    public function remoteStorage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BackupRemoteStorageConfig::class, 'remote_storage_id', 'id');
    }

    public function interval(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BackupIntervalOption::class, 'interval_id', 'id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BackupHistory::class);
    }

    public function latestHistory(): HasOne
    {
        return $this->hasOne(BackupHistory::class)->latestOfMany();
    }

    public function scopeMissedRun(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNotNull('stale_at')
                ->orWhere(fn (Builder $query): Builder => static::applyScheduleMissedConstraint($query));
        });
    }

    public function scopeNotMissedRun(Builder $query): Builder
    {
        return $query
            ->whereNull('stale_at')
            ->where(fn (Builder $query): Builder => static::applyScheduleNotMissedConstraint($query));
    }

    public function scopeAwaitingFirstRun(Builder $query): Builder
    {
        return $query
            ->notMissedRun()
            ->whereNull('last_history_at')
            ->doesntHave('histories');
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query
            ->notMissedRun()
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('last_history_at')
                    ->orWhereHas('histories');
            });
    }

    public function scopeLatestZipFailed(Builder $query): Builder
    {
        return $query->whereHas('latestHistory', fn (Builder $query): Builder => $query->where('is_zipped', false));
    }

    public function scopeLatestUploadFailed(Builder $query): Builder
    {
        return $query->whereHas('latestHistory', fn (Builder $query): Builder => $query->where('is_uploaded', false));
    }

    public function addExpectedInterval(CarbonInterface $date): ?CarbonInterface
    {
        $interval = $this->interval;

        if (! $interval) {
            return null;
        }

        return match (true) {
            str_contains($interval->unit, 'hour') => $date->copy()->addHours($interval->value),
            str_contains($interval->unit, 'day') => $date->copy()->addDays($interval->value),
            str_contains($interval->unit, 'week') => $date->copy()->addWeeks($interval->value),
            str_contains($interval->unit, 'month') => $date->copy()->addMonthsNoOverflow($interval->value),
            default => null,
        };
    }

    private static function applyScheduleMissedConstraint(Builder $query): Builder
    {
        $hasSchedule = false;
        $referenceSql = static::freshnessReferenceSql();

        foreach (static::backupIntervalCutoffs() as $intervalId => $cutoffAt) {
            $hasSchedule = true;

            $query->orWhere(fn (Builder $query): Builder => $query
                ->where('interval_id', $intervalId)
                ->whereRaw("{$referenceSql} < ?", [$cutoffAt]));
        }

        if (! $hasSchedule) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private static function applyScheduleNotMissedConstraint(Builder $query): Builder
    {
        $referenceSql = static::freshnessReferenceSql();

        foreach (static::backupIntervalCutoffs() as $intervalId => $cutoffAt) {
            $query->where(fn (Builder $query): Builder => $query
                ->where('interval_id', '!=', $intervalId)
                ->orWhereNull('interval_id')
                ->orWhereRaw("{$referenceSql} >= ?", [$cutoffAt]));
        }

        return $query;
    }

    private static function backupIntervalCutoffs(): array
    {
        return BackupIntervalOption::query()
            ->get(['id', 'value', 'unit'])
            ->mapWithKeys(function (BackupIntervalOption $interval): array {
                $cutoffAt = match (true) {
                    str_contains($interval->unit, 'hour') => now()->subHours($interval->value),
                    str_contains($interval->unit, 'day') => now()->subDays($interval->value),
                    str_contains($interval->unit, 'week') => now()->subWeeks($interval->value),
                    str_contains($interval->unit, 'month') => now()->subMonthsNoOverflow($interval->value),
                    default => null,
                };

                return $cutoffAt ? [$interval->id => $cutoffAt->toDateTimeString()] : [];
            })
            ->all();
    }

    private static function freshnessReferenceSql(): string
    {
        $backupTable = (new static)->getTable();
        $historyTable = (new BackupHistory)->getTable();

        return "COALESCE({$backupTable}.last_history_at, (SELECT MAX({$historyTable}.created_at) FROM {$historyTable} WHERE {$historyTable}.backup_id = {$backupTable}.id), {$backupTable}.first_run_at, {$backupTable}.created_at)";
    }

    public function latestHistoryReceivedAt(): ?CarbonInterface
    {
        return $this->last_history_at ?? $this->latestHistory?->created_at;
    }

    public function freshnessThresholdAt(): ?CarbonInterface
    {
        $referenceTime = $this->latestHistoryReceivedAt()
            ?? $this->first_run_at
            ?? $this->created_at;

        return $referenceTime
            ? $this->addExpectedInterval($referenceTime)
            : null;
    }

    public function isMissingExpectedRun(): bool
    {
        if ($this->stale_at !== null) {
            return true;
        }

        $thresholdAt = $this->freshnessThresholdAt();

        return $thresholdAt !== null && $thresholdAt->lt(now());
    }

    public function freshnessState(): string
    {
        if ($this->isMissingExpectedRun()) {
            return 'Missed run';
        }

        if ($this->latestHistoryReceivedAt() === null) {
            return 'Awaiting first run';
        }

        return 'Fresh';
    }

    public function freshnessSummary(): string
    {
        $thresholdAt = $this->freshnessThresholdAt();
        $lastHistoryAt = $this->latestHistoryReceivedAt();

        if ($this->isMissingExpectedRun()) {
            return 'No backup run has reported since '.($lastHistoryAt?->diffForHumans() ?? 'setup').'. Expected by '.($thresholdAt?->toDayDateTimeString() ?? 'the configured interval').'.';
        }

        if ($lastHistoryAt === null) {
            return $thresholdAt
                ? 'First run expected by '.$thresholdAt->toDayDateTimeString().'.'
                : 'No interval is configured for freshness tracking.';
        }

        return 'Last run reported '.$lastHistoryAt->diffForHumans().'.';
    }

    public function missedRunSummary(): string
    {
        $thresholdAt = $this->freshnessThresholdAt();
        $lastHistoryAt = $this->latestHistoryReceivedAt();
        $intervalLabel = $this->interval?->label ?? 'configured';

        if ($lastHistoryAt === null) {
            return "No backup run has reported after the expected {$intervalLabel} interval. Expected by ".($thresholdAt?->toDayDateTimeString() ?? 'the configured schedule').'.';
        }

        return "No backup run has reported since {$lastHistoryAt->toDayDateTimeString()}. Expected another run by ".($thresholdAt?->toDayDateTimeString() ?? "the {$intervalLabel} interval").'.';
    }

    public function markHistoryReceived(CarbonInterface $receivedAt): bool
    {
        $wasStale = $this->stale_at !== null;

        $this->forceFill([
            'last_history_at' => $receivedAt,
            'stale_at' => null,
        ])->save();

        return $wasStale;
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
        $backupId = $this->id;
        $token = $this->server?->token ?? '';
        $apiLogBackup = "$url/api/v1/backup-history";
        $firstRunAt = strtotime($this->first_run_at ?? now());
        $backupFilePrefix = escapeshellarg($fileName);
        $backupFileExtension = escapeshellarg($format);
        $maxAmountBackups = max(0, (int) $this->max_amount_backups);
        $deleteLocalOnFail = $this->delete_local_on_fail ? 1 : 0;

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
BACKUP_FILE_PREFIX=$backupFilePrefix
BACKUP_FILE_EXTENSION=$backupFileExtension
FILE_NAME="\${BACKUP_FILE_PREFIX}_\${TIMESTAMP}.\${BACKUP_FILE_EXTENSION}"
MAX_AMOUNT_BACKUPS=$maxAmountBackups
DELETE_LOCAL_ON_FAIL=$deleteLocalOnFail
FILE_SIZE=0
IS_UPLOADED=0
MESSAGE=""

cleanup_old_local_backups() {
    if [ "\$MAX_AMOUNT_BACKUPS" -le 0 ]; then
        return
    fi

    BACKUP_FILES=()
    BACKUP_STORAGE_DIR=\$(dirname -- "\$FILE_NAME")
    BACKUP_STORAGE_BASENAME=\$(basename -- "\$BACKUP_FILE_PREFIX")
    while IFS= read -r -d '' LOCAL_FILE; do
        LOCAL_BASENAME=\$(basename -- "\$LOCAL_FILE")
        if [[ "\$LOCAL_BASENAME" == "\$BACKUP_STORAGE_BASENAME"_*."\$BACKUP_FILE_EXTENSION" ]]; then
            LOCAL_MTIME=\$(stat -c %Y "\$LOCAL_FILE" 2>/dev/null || echo 0)
            BACKUP_FILES+=("\$LOCAL_MTIME \$LOCAL_FILE")
        fi
    done < <(find "\$BACKUP_STORAGE_DIR" -maxdepth 1 -type f -name "*.\$BACKUP_FILE_EXTENSION" -print0)

    BACKUP_COUNT=\${#BACKUP_FILES[@]}
    if [ "\$BACKUP_COUNT" -le "\$MAX_AMOUNT_BACKUPS" ]; then
        return
    fi

    mapfile -d '' SORTED_BACKUP_FILES < <(printf '%s\0' "\${BACKUP_FILES[@]}" | sort -z -n)
    DELETE_COUNT=\$((BACKUP_COUNT - MAX_AMOUNT_BACKUPS))
    for BACKUP_ENTRY in "\${SORTED_BACKUP_FILES[@]:0:\$DELETE_COUNT}"; do
        OLD_BACKUP_FILE="\${BACKUP_ENTRY#* }"
        rm -f -- "\$OLD_BACKUP_FILE"
    done
}

DO_ZIP="$($compressCmd 2>&1)"
DO_ZIP_STATUS=$?

if [ \$DO_ZIP_STATUS -eq 0 ]; then
    IS_ZIPPED=1
    FILE_SIZE=$(stat -c%s "\${FILE_NAME}")

    DO_UPLOAD_FILE="$($uploadCmd 2>&1)"
    UPLOAD_FILE_STATUS=$?
    if [ \$UPLOAD_FILE_STATUS -eq 0 ]; then
        IS_UPLOADED=1
        cleanup_old_local_backups
    else
        IS_UPLOADED=0
        MESSAGE="Upload failed: \$DO_UPLOAD_FILE"
        if [ "\$DELETE_LOCAL_ON_FAIL" -eq 1 ]; then
            rm -f -- "\$FILE_NAME"
        fi
    fi
else
    IS_ZIPPED=0
    MESSAGE="Compression failed: \$DO_ZIP"
fi

MESSAGE=\$(printf '%s' "\$MESSAGE" | head -c 4000)
MESSAGE_JSON=\$(printf '%s' "\$MESSAGE" | sed ':a;N;\$!ba;s/\\\\/\\\\\\\\/g;s/"/\\\\"/g;s/\\r/\\\\r/g;s/\\t/\\\\t/g;s/\\n/\\\\n/g')

curl -X POST $apiLogBackup \
 -H "Authorization: Bearer $token" \
 -H "Content-Type: application/json" \
 -H "Accept: application/json" \
 -d "{\"bi\": $backupId, \"iz\": \$IS_ZIPPED, \"iu\": \$IS_UPLOADED, \"sf\": \$FILE_SIZE, \"nf\": \"\$FILE_NAME\", \"msg\": \"\$MESSAGE_JSON\"}"
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
