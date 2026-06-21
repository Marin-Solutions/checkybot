<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\HealthEventNotificationService;
use Illuminate\Console\Command;

class MarkStaleBackups extends Command
{
    private const CHUNK_SIZE = 500;

    protected $signature = 'backups:mark-stale';

    protected $description = 'Mark backups as stale when their scheduled reporter stops posting history';

    public function handle(HealthEventNotificationService $notifications): int
    {
        Backup::query()
            ->with(['interval', 'server', 'latestHistory'])
            ->whereNull('stale_at')
            ->chunkById(self::CHUNK_SIZE, function ($backups) use ($notifications): void {
                $backups->each(function (Backup $backup) use ($notifications): void {
                    if (! $backup->isMissingExpectedRun()) {
                        return;
                    }

                    $backup->forceFill([
                        'stale_at' => now(),
                    ])->save();

                    $notifications->notifyBackup(
                        $backup,
                        'backup_missed',
                        'danger',
                        $backup->missedRunSummary(),
                    );
                });
            });

        return self::SUCCESS;
    }
}
