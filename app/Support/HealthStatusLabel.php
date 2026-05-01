<?php

namespace App\Support;

class HealthStatusLabel
{
    public static function format(?string $status): string
    {
        return match ($status) {
            'unknown' => 'Awaiting data',
            'healthy' => 'Healthy',
            'warning' => 'Warning',
            'danger' => 'Danger',
            default => ucfirst((string) $status),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $includeUnknown = true): array
    {
        $options = [
            'healthy' => self::format('healthy'),
            'warning' => self::format('warning'),
            'danger' => self::format('danger'),
        ];

        if (! $includeUnknown) {
            return $options;
        }

        return ['unknown' => self::format('unknown')] + $options;
    }
}
