<?php

namespace MarinSolutions\CheckybotLaravel\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;

class Interval
{
    public static function isDue(string $interval, ?CarbonInterface $lastReportedAt, CarbonInterface $now): bool
    {
        if ($lastReportedAt === null) {
            return true;
        }

        return $lastReportedAt->diffInSeconds($now) >= self::toSeconds($interval);
    }

    public static function toSeconds(string $interval): int
    {
        if (! preg_match('/^(\d+)([smhd])$/', $interval, $matches)) {
            throw new InvalidArgumentException("Invalid interval format [{$interval}].");
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            default => throw new InvalidArgumentException("Unsupported interval unit [{$unit}]."),
        };
    }
}
