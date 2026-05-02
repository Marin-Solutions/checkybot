<?php

namespace App\Support;

use App\Models\MonitorApiResult;
use Filament\Notifications\Notification;

class ApiMonitorRunNotification
{
    /**
     * @param  array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null}  $outcome
     */
    public static function send(array $outcome): void
    {
        $result = $outcome['result'];
        $status = $outcome['status'];

        $title = match ($status) {
            'danger' => 'On-demand run failed',
            'warning' => 'On-demand run is degraded',
            default => 'On-demand run succeeded',
        };

        $code = (int) ($result->http_code ?? 0);
        $codeLine = $code > 0 ? "HTTP {$code}" : 'No HTTP response';
        $responseTimeMs = (int) ($result->response_time_ms ?? 0);
        $failedCount = is_array($result->failed_assertions) ? count($result->failed_assertions) : 0;

        $lines = [
            'Recorded to run history.',
            "{$codeLine} • {$responseTimeMs}ms",
        ];

        if ($failedCount > 0) {
            $lines[] = $failedCount === 1
                ? '1 assertion failed.'
                : "{$failedCount} assertions failed.";
        }

        if (filled($outcome['summary'])) {
            $lines[] = $outcome['summary'];
        }

        $body = implode('<br>', array_map(fn (string $line): string => e($line), $lines));

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($status) {
            'danger' => $notification->danger(),
            'warning' => $notification->warning(),
            default => $notification->success(),
        };

        $notification->send();
    }
}
