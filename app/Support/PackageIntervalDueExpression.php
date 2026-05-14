<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;

class PackageIntervalDueExpression
{
    /**
     * @return array{0: string, 1: array<int, string>}
     */
    public static function build(ConnectionInterface $connection, string $operator = '<=', string $anchorColumn = 'last_heartbeat_at'): array
    {
        $now = now()->toDateTimeString();
        $operator = in_array($operator, ['<', '<=', '>', '>='], true) ? $operator : '<=';
        $anchorColumn = in_array($anchorColumn, ['last_heartbeat_at', 'awaiting_heartbeat_since', 'created_at'], true)
            ? $anchorColumn
            : 'last_heartbeat_at';

        // Mirrors IntervalParser formats so legacy package intervals continue to schedule.
        // Seconds are rounded up to full minutes to match IntervalParser::toMinutes().
        // The numeric segment is capped at 6 digits so SQL date arithmetic cannot
        // overflow driver integer limits when legacy rows contain oversized strings.
        return match ($connection->getDriverName()) {
            'sqlite' => [
                '('
                    ."package_interval GLOB '[0-9]*[smhd]'"
                    ." AND substr(package_interval, 1, length(package_interval) - 1) NOT GLOB '*[^0-9]*'"
                    ." AND substr(package_interval, 1, length(package_interval) - 1) GLOB '*[1-9]*'"
                    .' AND length(substr(package_interval, 1, length(package_interval) - 1)) <= 6'
                    ." AND datetime({$anchorColumn}, '+' || (CASE substr(package_interval, -1)"
                    ." WHEN 's' THEN CAST((CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) + 59) / 60 AS INTEGER)"
                    ." WHEN 'm' THEN CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER)"
                    ." WHEN 'h' THEN CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) * 60"
                    ." WHEN 'd' THEN CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) * 1440"
                    ." END) || ' minutes') {$operator} ?"
                    .') OR ('
                    ."package_interval GLOB 'every_[0-9]*_*'"
                    ." AND substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) NOT GLOB '*[^0-9]*'"
                    ." AND substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) GLOB '*[1-9]*'"
                    .' AND length(substr(package_interval, 7, instr(substr(package_interval, 7), \'_\') - 1)) <= 6'
                    ." AND substr(package_interval, 7 + instr(substr(package_interval, 7), '_')) IN ('second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days')"
                    ." AND datetime({$anchorColumn}, '+' || (CASE substr(package_interval, 7 + instr(substr(package_interval, 7), '_'))"
                    ." WHEN 'second' THEN CAST((CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) + 59) / 60 AS INTEGER)"
                    ." WHEN 'seconds' THEN CAST((CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) + 59) / 60 AS INTEGER)"
                    ." WHEN 'minute' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER)"
                    ." WHEN 'minutes' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER)"
                    ." WHEN 'hour' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 60"
                    ." WHEN 'hours' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 60"
                    ." WHEN 'day' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 1440"
                    ." WHEN 'days' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 1440"
                    ." END) || ' minutes') {$operator} ?"
                    .')',
                [$now, $now],
            ],
            'pgsql' => [
                '('
                    ."package_interval ~ '^[0-9]*[1-9][0-9]*[smhd]$'"
                    .' AND char_length(package_interval) - 1 <= 6'
                    ." AND date_trunc('second', {$anchorColumn}) + ((CASE right(package_interval, 1)"
                    ." WHEN 's' THEN ((substring(package_interval from 1 for char_length(package_interval) - 1)::integer + 59) / 60)"
                    ." WHEN 'm' THEN substring(package_interval from 1 for char_length(package_interval) - 1)::integer"
                    ." WHEN 'h' THEN substring(package_interval from 1 for char_length(package_interval) - 1)::integer * 60"
                    ." WHEN 'd' THEN substring(package_interval from 1 for char_length(package_interval) - 1)::integer * 1440"
                    ." END) * interval '1 minute') {$operator} ?"
                    .') OR ('
                    ."package_interval ~ '^every_[0-9]*[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'"
                    ." AND char_length(substring(package_interval from '^every_([0-9]+)_')) <= 6"
                    ." AND date_trunc('second', {$anchorColumn}) + ((CASE substring(package_interval from '^every_[0-9]+_(.*)$')"
                    ." WHEN 'second' THEN ((substring(package_interval from '^every_([0-9]+)_')::integer + 59) / 60)"
                    ." WHEN 'seconds' THEN ((substring(package_interval from '^every_([0-9]+)_')::integer + 59) / 60)"
                    ." WHEN 'minute' THEN substring(package_interval from '^every_([0-9]+)_')::integer"
                    ." WHEN 'minutes' THEN substring(package_interval from '^every_([0-9]+)_')::integer"
                    ." WHEN 'hour' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 60"
                    ." WHEN 'hours' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 60"
                    ." WHEN 'day' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 1440"
                    ." WHEN 'days' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 1440"
                    ." END) * interval '1 minute') {$operator} ?"
                    .')',
                [$now, $now],
            ],
            'sqlsrv' => [
                '('
                    ."package_interval LIKE '[0-9]%[smhd]'"
                    .' AND PATINDEX(\'%[^0-9]%\', LEFT(package_interval, LEN(package_interval) - 1)) = 0'
                    .' AND PATINDEX(\'%[1-9]%\', LEFT(package_interval, LEN(package_interval) - 1)) > 0'
                    .' AND LEN(package_interval) - 1 <= 6'
                    .' AND DATEADD(minute, CASE RIGHT(package_interval, 1)'
                    ." WHEN 's' THEN (CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) + 59) / 60"
                    ." WHEN 'm' THEN CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int)"
                    ." WHEN 'h' THEN CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) * 60"
                    ." WHEN 'd' THEN CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) * 1440"
                    ." END, {$anchorColumn}) {$operator} ?"
                    .') OR ('
                    ."package_interval LIKE 'every[_][0-9]%[_]%'"
                    ." AND CHARINDEX('_', package_interval, 7) > 0"
                    ." AND PATINDEX('%[^0-9]%', SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7)) = 0"
                    ." AND PATINDEX('%[1-9]%', SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7)) > 0"
                    ." AND CHARINDEX('_', package_interval, 7) - 7 <= 6"
                    ." AND SUBSTRING(package_interval, CHARINDEX('_', package_interval, 7) + 1, LEN(package_interval)) IN ('second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days')"
                    ." AND DATEADD(minute, CASE SUBSTRING(package_interval, CHARINDEX('_', package_interval, 7) + 1, LEN(package_interval))"
                    ." WHEN 'second' THEN (CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int) + 59) / 60"
                    ." WHEN 'seconds' THEN (CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int) + 59) / 60"
                    ." WHEN 'minute' THEN CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int)"
                    ." WHEN 'minutes' THEN CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int)"
                    ." WHEN 'hour' THEN CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int) * 60"
                    ." WHEN 'hours' THEN CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int) * 60"
                    ." WHEN 'day' THEN CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int) * 1440"
                    ." WHEN 'days' THEN CAST(SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7) AS int) * 1440"
                    ." END, {$anchorColumn}) {$operator} ?"
                    .')',
                [$now, $now],
            ],
            default => [
                '('
                    ."package_interval REGEXP '^[0-9]*[1-9][0-9]*[smhd]$'"
                    .' AND CHAR_LENGTH(package_interval) - 1 <= 6'
                    .' AND TIMESTAMPADD(MINUTE, CASE RIGHT(package_interval, 1)'
                    ." WHEN 's' THEN FLOOR((CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) + 59) / 60)"
                    ." WHEN 'm' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED)"
                    ." WHEN 'h' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * 60"
                    ." WHEN 'd' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * 1440"
                    ." END, {$anchorColumn}) {$operator} ?"
                    .') OR ('
                    ."package_interval REGEXP '^every_[0-9]*[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'"
                    ." AND CHAR_LENGTH(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1)) <= 6"
                    ." AND TIMESTAMPADD(MINUTE, CASE SUBSTRING_INDEX(package_interval, '_', -1)"
                    ." WHEN 'second' THEN FLOOR((CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) + 59) / 60)"
                    ." WHEN 'seconds' THEN FLOOR((CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) + 59) / 60)"
                    ." WHEN 'minute' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED)"
                    ." WHEN 'minutes' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED)"
                    ." WHEN 'hour' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 60"
                    ." WHEN 'hours' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 60"
                    ." WHEN 'day' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 1440"
                    ." WHEN 'days' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 1440"
                    ." END, {$anchorColumn}) {$operator} ?"
                    .')',
                [$now, $now],
            ],
        };
    }
}
