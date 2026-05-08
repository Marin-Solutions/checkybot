<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExpireStuckMonitorActions extends Command
{
    protected $signature = 'monitor-actions:expire-stuck
        {--diagnostic-minutes=30 : Expire website and API diagnostics queued longer than this many minutes}
        {--outbound-minutes=120 : Expire outbound scans queued longer than this many minutes}';

    protected $description = 'Clear abandoned queued monitor action states so operators can retry';

    public function handle(): int
    {
        $diagnosticMinutes = $this->positiveIntegerOption('diagnostic-minutes');
        $outboundMinutes = $this->positiveIntegerOption('outbound-minutes');

        $websiteDiagnostics = $this->expireQueuedState(
            Website::query(),
            'diagnostic_queued_at',
            now()->subMinutes($diagnosticMinutes),
            $diagnosticMinutes,
            'website diagnostic',
            'website_id',
        );

        $apiDiagnostics = $this->expireQueuedState(
            MonitorApis::query(),
            'diagnostic_queued_at',
            now()->subMinutes($diagnosticMinutes),
            $diagnosticMinutes,
            'API diagnostic',
            'monitor_api_id',
        );

        $outboundScans = $this->expireQueuedState(
            Website::query(),
            'outbound_scan_queued_at',
            now()->subMinutes($outboundMinutes),
            $outboundMinutes,
            'outbound scan',
            'website_id',
        );

        $total = $websiteDiagnostics + $apiDiagnostics + $outboundScans;

        $this->info("Expired {$total} stuck monitor actions ({$websiteDiagnostics} website diagnostics, {$apiDiagnostics} API diagnostics, {$outboundScans} outbound scans).");

        return self::SUCCESS;
    }

    private function expireQueuedState(
        Builder $query,
        string $queuedColumn,
        Carbon $cutoff,
        int $thresholdMinutes,
        string $action,
        string $logKey,
    ): int {
        $expired = 0;

        $query
            ->whereNotNull($queuedColumn)
            ->where($queuedColumn, '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$expired, $queuedColumn, $thresholdMinutes, $action, $logKey): void {
                foreach ($records as $record) {
                    $queuedAt = $record->{$queuedColumn};

                    $updated = $record->newQuery()
                        ->whereKey($record->getKey())
                        ->where($queuedColumn, $queuedAt)
                        ->update([$queuedColumn => null]);

                    if ($updated === 0) {
                        continue;
                    }

                    $expired++;

                    Log::warning('Expired stuck monitor action.', [
                        $logKey => $record->getKey(),
                        'action' => $action,
                        'queued_column' => $queuedColumn,
                        'queued_at' => $this->queuedAtIso($record, $queuedColumn),
                        'threshold_minutes' => $thresholdMinutes,
                    ]);
                }
            });

        return $expired;
    }

    private function queuedAtIso(Model $record, string $queuedColumn): ?string
    {
        $queuedAt = $record->{$queuedColumn};

        return $queuedAt instanceof Carbon
            ? $queuedAt->toIso8601String()
            : null;
    }

    private function positiveIntegerOption(string $name): int
    {
        $value = (int) $this->option($name);

        if ($value < 1) {
            $this->fail("The --{$name} option must be at least 1.");
        }

        return $value;
    }
}
