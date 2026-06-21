<?php

namespace App\Support;

class HealthStatusLabel
{
    public static function format(?string $status): string
    {
        return match ($status) {
            null, 'unknown' => 'Pending',
            'pending' => 'Pending',
            'healthy' => 'Healthy',
            'warning' => 'Warning',
            'danger' => 'Failing',
            default => ucfirst((string) $status),
        };
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            'healthy' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'gray',
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

        return [
            'unknown' => self::format('unknown'),
        ] + $options;
    }
}
