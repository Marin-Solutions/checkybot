<?php

namespace App\Support;

class NotificationStatus
{
    public static function label(string $event, string $status): string
    {
        return match ($event) {
            'recovered', 'recovery' => 'RECOVERED',
            'stale' => 'STALE',
            default => strtoupper($status),
        };
    }

    public static function emoji(string $event, string $status): string
    {
        if (in_array($event, ['recovered', 'recovery'], true)) {
            return '✅';
        }

        return match ($status) {
            'danger' => '🔴',
            'warning' => '🟡',
            'healthy' => '✅',
            default => 'ℹ️',
        };
    }

    public static function prefix(string $event, string $status): string
    {
        return self::emoji($event, $status).' ['.self::label($event, $status).']';
    }
}
