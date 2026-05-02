<?php

namespace App\Console\Commands;

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class WriteJobCheckSsl extends Command
{
    private const CHUNK_SIZE = 100;

    private const REMINDER_DAYS = [14, 7, 3, 2, 1, 0];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ssl:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check SSL certificates and send reminders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->sslExpiryDay();

        $this->info('SSL check completed successfully.');

        return Command::SUCCESS;
    }

    protected function sslExpiryDay(): void
    {
        $this->dueSslChecksQuery()
            ->chunkById(self::CHUNK_SIZE, function ($websites): void {
                $websites->each(function (Website $website): void {
                    CheckSslExpiryDateJob::dispatch($website)->onQueue('ssl-check');
                });
            });
    }

    protected function dueSslChecksQuery(): Builder
    {
        return Website::query()
            ->where('ssl_check', true)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $this->wherePackageSslOnlyCheck($query);
                        $this->wherePackageSslCheckIsDue($query);
                    })
                    ->orWhere(function (Builder $query): void {
                        $this->whereNotPackageSslOnlyCheck($query);
                        $this->whereSslReminderIsDue($query);
                    });
            });
    }

    private function whereSslReminderIsDue(Builder $query): void
    {
        $today = Carbon::today();

        $query->where(function (Builder $query) use ($today): void {
            $query
                ->whereNull('ssl_expiry_date')
                ->orWhere('ssl_expiry_date', '<', $today->toDateString())
                ->orWhere(function (Builder $query) use ($today): void {
                    collect(self::REMINDER_DAYS)
                        ->unique()
                        ->each(function (int $days) use ($query, $today): void {
                            $reminderDate = $today->copy()->addDays($days);

                            $query->orWhere(function (Builder $query) use ($reminderDate): void {
                                $query
                                    ->where('ssl_expiry_date', '>=', $reminderDate->toDateString())
                                    ->where('ssl_expiry_date', '<', $reminderDate->copy()->addDay()->toDateString());
                            });
                        });
                });
        });
    }

    private function wherePackageSslOnlyCheck(Builder $query): void
    {
        $query
            ->where('source', 'package')
            ->where('uptime_check', false)
            ->whereNotNull('package_interval')
            ->where('package_interval', '!=', '');
    }

    private function whereNotPackageSslOnlyCheck(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->where('source', '!=', 'package')
                ->orWhere('uptime_check', true)
                ->orWhereNull('package_interval')
                ->orWhere('package_interval', '');
        });
    }

    private function wherePackageSslCheckIsDue(Builder $query): void
    {
        [$intervalDueSql, $bindings] = $this->packageIntervalDueExpression();

        $query->where(function (Builder $query) use ($intervalDueSql, $bindings): void {
            $query
                ->whereNull('last_heartbeat_at')
                ->orWhereRaw($intervalDueSql, $bindings);
        });
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function packageIntervalDueExpression(): array
    {
        $now = now()->toDateTimeString();

        // Mirrors IntervalParser formats so legacy package intervals continue to schedule.
        // PackageSyncRequest rejects seconds for new SSL checks, but older rows may exist.
        return match (Website::query()->getConnection()->getDriverName()) {
            'sqlite' => [
                '('
                    ."package_interval GLOB '[1-9]*[smhd]'"
                    ." AND substr(package_interval, 1, length(package_interval) - 1) NOT GLOB '*[^0-9]*'"
                    ." AND datetime(last_heartbeat_at, '+' || (CASE substr(package_interval, -1)"
                    ." WHEN 's' THEN CAST((CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) + 59) / 60 AS INTEGER)"
                    ." WHEN 'm' THEN CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER)"
                    ." WHEN 'h' THEN CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) * 60"
                    ." WHEN 'd' THEN CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) * 1440"
                    ." END) || ' minutes') <= ?"
                    .') OR ('
                    ."package_interval GLOB 'every_[1-9]*_*'"
                    ." AND substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) NOT GLOB '*[^0-9]*'"
                    ." AND substr(package_interval, 7 + instr(substr(package_interval, 7), '_')) IN ('second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days')"
                    ." AND datetime(last_heartbeat_at, '+' || (CASE substr(package_interval, 7 + instr(substr(package_interval, 7), '_'))"
                    ." WHEN 'second' THEN CAST((CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) + 59) / 60 AS INTEGER)"
                    ." WHEN 'seconds' THEN CAST((CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) + 59) / 60 AS INTEGER)"
                    ." WHEN 'minute' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER)"
                    ." WHEN 'minutes' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER)"
                    ." WHEN 'hour' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 60"
                    ." WHEN 'hours' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 60"
                    ." WHEN 'day' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 1440"
                    ." WHEN 'days' THEN CAST(substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1) AS INTEGER) * 1440"
                    ." END) || ' minutes') <= ?"
                    .')',
                [$now, $now],
            ],
            'pgsql' => [
                '('
                    ."package_interval ~ '^[1-9][0-9]*[smhd]$'"
                    ." AND date_trunc('second', last_heartbeat_at) + ((CASE right(package_interval, 1)"
                    ." WHEN 's' THEN ((substring(package_interval from 1 for char_length(package_interval) - 1)::integer + 59) / 60)"
                    ." WHEN 'm' THEN substring(package_interval from 1 for char_length(package_interval) - 1)::integer"
                    ." WHEN 'h' THEN substring(package_interval from 1 for char_length(package_interval) - 1)::integer * 60"
                    ." WHEN 'd' THEN substring(package_interval from 1 for char_length(package_interval) - 1)::integer * 1440"
                    ." END) * interval '1 minute') <= ?"
                    .') OR ('
                    ."package_interval ~ '^every_[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'"
                    ." AND date_trunc('second', last_heartbeat_at) + ((CASE substring(package_interval from '^every_[0-9]+_(.*)$')"
                    ." WHEN 'second' THEN ((substring(package_interval from '^every_([0-9]+)_')::integer + 59) / 60)"
                    ." WHEN 'seconds' THEN ((substring(package_interval from '^every_([0-9]+)_')::integer + 59) / 60)"
                    ." WHEN 'minute' THEN substring(package_interval from '^every_([0-9]+)_')::integer"
                    ." WHEN 'minutes' THEN substring(package_interval from '^every_([0-9]+)_')::integer"
                    ." WHEN 'hour' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 60"
                    ." WHEN 'hours' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 60"
                    ." WHEN 'day' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 1440"
                    ." WHEN 'days' THEN substring(package_interval from '^every_([0-9]+)_')::integer * 1440"
                    ." END) * interval '1 minute') <= ?"
                    .')',
                [$now, $now],
            ],
            'sqlsrv' => [
                '('
                    ."package_interval LIKE '[1-9]%[smhd]'"
                    .' AND PATINDEX(\'%[^0-9]%\', LEFT(package_interval, LEN(package_interval) - 1)) = 0'
                    .' AND DATEADD(minute, CASE RIGHT(package_interval, 1)'
                    ." WHEN 's' THEN (CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) + 59) / 60"
                    ." WHEN 'm' THEN CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int)"
                    ." WHEN 'h' THEN CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) * 60"
                    ." WHEN 'd' THEN CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) * 1440"
                    .' END, last_heartbeat_at) <= ?'
                    .') OR ('
                    ."package_interval LIKE 'every[_][1-9]%[_]%'"
                    ." AND CHARINDEX('_', package_interval, 7) > 0"
                    ." AND PATINDEX('%[^0-9]%', SUBSTRING(package_interval, 7, CHARINDEX('_', package_interval, 7) - 7)) = 0"
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
                    .' END, last_heartbeat_at) <= ?'
                    .')',
                [$now, $now],
            ],
            default => [
                '('
                    ."package_interval REGEXP '^[1-9][0-9]*[smhd]$'"
                    .' AND TIMESTAMPADD(MINUTE, CASE RIGHT(package_interval, 1)'
                    ." WHEN 's' THEN FLOOR((CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) + 59) / 60)"
                    ." WHEN 'm' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED)"
                    ." WHEN 'h' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * 60"
                    ." WHEN 'd' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * 1440"
                    .' END, last_heartbeat_at) <= ?'
                    .') OR ('
                    ."package_interval REGEXP '^every_[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'"
                    ." AND TIMESTAMPADD(MINUTE, CASE SUBSTRING_INDEX(package_interval, '_', -1)"
                    ." WHEN 'second' THEN FLOOR((CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) + 59) / 60)"
                    ." WHEN 'seconds' THEN FLOOR((CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) + 59) / 60)"
                    ." WHEN 'minute' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED)"
                    ." WHEN 'minutes' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED)"
                    ." WHEN 'hour' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 60"
                    ." WHEN 'hours' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 60"
                    ." WHEN 'day' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 1440"
                    ." WHEN 'days' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 1440"
                    .' END, last_heartbeat_at) <= ?'
                    .')',
                [$now, $now],
            ],
        };
    }
}
