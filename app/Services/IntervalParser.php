<?php

namespace App\Services;

class IntervalParser
{
    /**
     * Parse interval string to minutes
     *
     * Converts strings like '5m', '2h', '1d' to minutes
     *
     * @param  string  $interval  Format: {number}{unit} where unit is m (minutes), h (hours), d (days)
     * @return int Minutes
     *
     * @throws \InvalidArgumentException
     */
    public static function toMinutes(string $interval): int
    {
        if (! preg_match('/^(\d+)([mhd])$/', $interval, $matches)) {
            throw new \InvalidArgumentException("Invalid interval format: {$interval}. Expected format: {number}{m|h|d}");
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
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
        return (bool) preg_match('/^(\d+)([mhd])$/', $interval);
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
