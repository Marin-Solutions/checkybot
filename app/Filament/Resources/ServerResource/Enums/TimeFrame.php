<?php

namespace App\Filament\Resources\ServerResource\Enums;

enum TimeFrame: string
{
    case LAST_HOUR = '1 HOUR';
    case LAST_2_HOURS = '2 HOUR';
    case LAST_4_HOURS = '4 HOUR';
    case LAST_6_HOURS = '6 HOUR';
    case LAST_12_HOURS = '12 HOUR';
    case LAST_24_HOURS = '24 HOUR';
    case LAST_48_HOURS = '48 HOUR';
    case LAST_3_DAYS = '3 DAY';
    case LAST_7_DAYS = '7 DAY';
    case LAST_14_DAYS = '14 DAY';
    case LAST_28_DAYS = '28 DAY';

    public function label(): string
    {
        return match ($this) {
            TimeFrame::LAST_HOUR => 'Last 1 Hour',
            TimeFrame::LAST_2_HOURS => 'Last 2 Hours',
            TimeFrame::LAST_4_HOURS => 'Last 4 Hours',
            TimeFrame::LAST_6_HOURS => 'Last 6 Hours',
            TimeFrame::LAST_12_HOURS => 'Last 12 Hours',
            TimeFrame::LAST_24_HOURS => 'Last 24 Hours',
            TimeFrame::LAST_48_HOURS => 'Last 48 Hours',
            TimeFrame::LAST_3_DAYS => 'Last 3 Days',
            TimeFrame::LAST_7_DAYS => 'Last 7 Days',
            TimeFrame::LAST_14_DAYS => 'Last 14 Days',
            TimeFrame::LAST_28_DAYS => 'Last 28 Days',
        };
    }

    public static function toArray(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            $array[$case->value] = $case->label();
        }

        return $array;
    }

    public static function getOptionsArray(): array
    {
        return self::toArray();
    }

    public static function getDefaultTimeframe(): self
    {
        return self::LAST_24_HOURS;
    }
}
