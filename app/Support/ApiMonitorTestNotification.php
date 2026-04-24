<?php

namespace App\Support;

use App\Services\PackageHealthStatusService;
use Filament\Notifications\Notification;

class ApiMonitorTestNotification
{
    /**
     * Build a Filament notification that summarizes the outcome of a
     * {@see \App\Models\MonitorApis::testApi()} run for the operator, including
     * the HTTP status, response time, and per-assertion pass/fail feedback.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromResult(array $result, ?int $expectedStatus = null): Notification
    {
        $status = app(PackageHealthStatusService::class)
            ->apiStatusFromResult($result, $expectedStatus);

        $code = (int) ($result['code'] ?? 0);
        $responseTimeMs = (int) ($result['response_time_ms'] ?? 0);
        $assertions = $result['assertions'] ?? [];
        $failedAssertions = array_values(array_filter(
            $assertions,
            fn (array $assertion): bool => ! ($assertion['passed'] ?? false),
        ));
        $onlyStatusFailures = ! empty($failedAssertions)
            && collect($failedAssertions)->every(
                fn (array $assertion): bool => ($assertion['path'] ?? null) === '_http_status',
            );

        $notificationType = match ($status) {
            'danger' => 'danger',
            'warning' => 'warning',
            default => 'success',
        };

        if ($code === 0) {
            $title = 'API request failed';
        } else {
            $title = match ($status) {
                'danger' => 'API request failed',
                'warning' => ! empty($failedAssertions) && ! $onlyStatusFailures
                    ? 'Some API assertions failed'
                    : 'API response is degraded',
                default => 'API response received',
            };
        }

        $body = self::formatBody($result, $code, $responseTimeMs, $assertions);

        return Notification::make()
            ->{$notificationType}()
            ->title(__($title))
            ->body(__($body));
    }

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     */
    private static function formatBody(array $result, int $code, int $responseTimeMs, array $assertions): string
    {
        $lines = [];

        if ($code === 0) {
            $error = $result['error'] ?? 'The API request could not be completed.';
            $lines[] = (string) $error;
        } else {
            $lines[] = "HTTP {$code} • {$responseTimeMs}ms";
        }

        if (! empty($assertions)) {
            foreach ($assertions as $assertion) {
                $icon = ($assertion['passed'] ?? false) ? '✓' : '✗';
                $path = $assertion['path'] ?? '';
                $type = $assertion['type'] ?? 'exists';
                $message = $assertion['message'] ?? '';

                $suffix = $type !== 'exists' ? " [{$type}]" : '';
                $lines[] = trim("{$icon} Path: {$path}{$suffix} - {$message}");
            }
        } elseif ($code !== 0) {
            $lines[] = 'No assertions configured for this API endpoint.';
        }

        return implode("\n", $lines);
    }
}
