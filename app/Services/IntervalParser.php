<?php

namespace App\Services;

class IntervalParser
{
    /**
     * Normalize supported interval formats to the compact storage form.
     */
    public static function normalize(string $interval): string
    {
        $interval = trim($interval);

        if (preg_match('/^(\d+)([smhd])$/', $interval, $matches)) {
            return (int) $matches[1].$matches[2];
        }

        if (preg_match('/^every_(\d+)_(second|seconds|minute|minutes|hour|hours|day|days)$/', $interval, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'second', 'seconds' => "{$value}s",
                'minute', 'minutes' => "{$value}m",
                'hour', 'hours' => "{$value}h",
                'day', 'days' => "{$value}d",
            };
        }

        throw new \InvalidArgumentException("Invalid interval format: {$interval}. Expected format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}");
    }

    /**
     * Parse interval string to minutes
     *
     * Converts strings like '5m', '2h', '1d', '30s' to minutes
     *
     * @param  string  $interval  Format: {number}{unit} where unit is s (seconds), m (minutes), h (hours), d (days)
     * @return int Minutes
     *
     * @throws \InvalidArgumentException
     */
    public static function toMinutes(string $interval): int
    {
        $interval = self::normalize($interval);
        preg_match('/^(\d+)([smhd])$/', $interval, $matches);

        $value = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
            's' => (int) ceil($value / 60), // Convert seconds to minutes (ceiling)
            'm' => $value,
            'h' => $value * 60,
            'd' => $value * 1440,
            default => throw new \InvalidArgumentException("Unknown interval unit: {$unit}")
        };
    }

    /**
     * Validate interval string format
     */
    public static function isValid(string $interval): bool
    {
        try {
            self::normalize($interval);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Convert minutes back to interval string (for display purposes)
     */
    public static function fromMinutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            return ($minutes / 1440).'d';
        }

        if ($minutes % 60 === 0) {
            return ($minutes / 60).'h';
        }

        return $minutes.'m';
    }
}
