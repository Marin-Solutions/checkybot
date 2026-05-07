<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledApiMonitorJob;
use App\Models\MonitorApis;
use App\Services\IntervalParser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckApiMonitors extends Command
{
    protected $signature = 'monitor:check-apis';

    protected $description = 'Check all API monitors and record their results';

    public function handle(): int
    {
        $this->info('Queueing due API monitor checks...');
        $count = 0;

        MonitorApis::query()
            ->where('is_enabled', true)
            ->where(fn (Builder $query): Builder => $this->whereDue($query))
            ->chunkById(100, function ($monitors) use (&$count): void {
                foreach ($monitors as $monitor) {
                    $this->warnIfInvalidPollingInterval($monitor);

                    RunScheduledApiMonitorJob::dispatch($monitor->withoutRelations());
                    $count++;
                }
            });

        $this->info("Queued {$count} API monitor jobs.");

        return Command::SUCCESS;
    }

    private function whereDue(Builder $query): Builder
    {
        $now = now()->startOfMinute();
        $validIntervalSql = $this->validIntervalSql();
        $intervalMinutesSql = $this->intervalMinutesSql();

        return $query
            ->whereNull('package_interval')
            ->orWhere('package_interval', '')
            ->orWhereNull('last_heartbeat_at')
            ->orWhereRaw("not ({$validIntervalSql})")
            ->orWhere(function (Builder $query) use ($validIntervalSql, $intervalMinutesSql, $now): void {
                $query
                    ->whereRaw($validIntervalSql)
                    ->whereRaw($this->dueAtSql($intervalMinutesSql), [$now]);
            });
    }

    private function warnIfInvalidPollingInterval(MonitorApis $monitor): void
    {
        if (blank($monitor->package_interval)) {
            return;
        }

        try {
            IntervalParser::toMinutes($monitor->package_interval);
        } catch (\InvalidArgumentException $exception) {
            Log::warning('API monitor has an invalid polling interval; running on the default cadence.', [
                'monitor_id' => $monitor->id,
                'monitor_title' => $monitor->title,
                'package_interval' => $monitor->package_interval,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function dueAtSql(string $intervalMinutesSql): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "datetime(strftime('%Y-%m-%d %H:%M:00', last_heartbeat_at), '+' || ({$intervalMinutesSql}) || ' minutes') <= ?",
            'pgsql' => "date_trunc('minute', last_heartbeat_at) + make_interval(mins => least(({$intervalMinutesSql}), 2147483647)::int) <= ?",
            'sqlsrv' => "DATEADD(minute, ({$intervalMinutesSql}), DATEADD(minute, DATEDIFF(minute, 0, last_heartbeat_at), 0)) <= ?",
            default => "DATE_ADD(DATE_FORMAT(last_heartbeat_at, '%Y-%m-%d %H:%i:00'), INTERVAL ({$intervalMinutesSql}) MINUTE) <= ?",
        };
    }

    private function validIntervalSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => $this->sqliteValidIntervalSql(),
            'pgsql' => "package_interval ~ '^0*[1-9][0-9]*[smhd]$' or package_interval ~ '^every_0*[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'",
            'sqlsrv' => $this->sqlServerValidIntervalSql(),
            default => "package_interval regexp '^0*[1-9][0-9]*[smhd]$' or package_interval regexp '^every_0*[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'",
        };
    }

    private function intervalMinutesSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => $this->sqliteIntervalMinutesSql(),
            'pgsql' => $this->postgresIntervalMinutesSql(),
            'sqlsrv' => $this->sqlServerIntervalMinutesSql(),
            default => $this->mysqlIntervalMinutesSql(),
        };
    }

    private function sqliteValidIntervalSql(): string
    {
        $compactValue = 'substr(package_interval, 1, length(package_interval) - 1)';
        $legacyValue = $this->sqliteLegacyValueSql();
        $legacyUnit = $this->sqliteLegacyUnitSql();

        return "(
            (
                length(package_interval) > 1
                and substr(package_interval, -1) in ('s', 'm', 'h', 'd')
                and {$compactValue} not glob '*[^0-9]*'
                and cast({$compactValue} as integer) > 0
            )
            or (
                package_interval like 'every\\_%' escape '\\'
                and instr(substr(package_interval, 7), '_') > 1
                and {$legacyValue} not glob '*[^0-9]*'
                and cast({$legacyValue} as integer) > 0
                and {$legacyUnit} in ('second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days')
            )
        )";
    }

    private function sqliteIntervalMinutesSql(): string
    {
        $compactValue = 'cast(substr(package_interval, 1, length(package_interval) - 1) as integer)';
        $compactUnit = 'substr(package_interval, -1)';
        $compactValid = "(
            length(package_interval) > 1
            and {$compactUnit} in ('s', 'm', 'h', 'd')
            and substr(package_interval, 1, length(package_interval) - 1) not glob '*[^0-9]*'
            and {$compactValue} > 0
        )";
        $legacyValue = "cast({$this->sqliteLegacyValueSql()} as integer)";
        $legacyUnit = $this->sqliteLegacyUnitSql();

        return "case
            when {$legacyUnit} in ('second', 'seconds') then cast(({$legacyValue} + 59) / 60 as integer)
            when {$legacyUnit} in ('minute', 'minutes') then {$legacyValue}
            when {$legacyUnit} in ('hour', 'hours') then {$legacyValue} * 60
            when {$legacyUnit} in ('day', 'days') then {$legacyValue} * 1440
            when {$compactValid} and {$compactUnit} = 's' then cast(({$compactValue} + 59) / 60 as integer)
            when {$compactValid} and {$compactUnit} = 'm' then {$compactValue}
            when {$compactValid} and {$compactUnit} = 'h' then {$compactValue} * 60
            when {$compactValid} and {$compactUnit} = 'd' then {$compactValue} * 1440
        end";
    }

    private function sqliteLegacyValueSql(): string
    {
        return "substr(package_interval, 7, instr(substr(package_interval, 7), '_') - 1)";
    }

    private function sqliteLegacyUnitSql(): string
    {
        return "substr(package_interval, 7 + instr(substr(package_interval, 7), '_'))";
    }

    private function postgresIntervalMinutesSql(): string
    {
        $compactValue = "(substring(package_interval from '^[0-9]+'))::numeric";
        $compactUnit = 'right(package_interval, 1)';
        $legacyValue = "(substring(package_interval from '^every_([0-9]+)_'))::numeric";
        $legacyUnit = "substring(package_interval from '^every_[0-9]+_(.+)$')";

        return "case
            when package_interval ~ '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 's' then ceiling({$compactValue} / 60.0)
            when package_interval ~ '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 'm' then {$compactValue}
            when package_interval ~ '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 'h' then {$compactValue} * 60
            when package_interval ~ '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 'd' then {$compactValue} * 1440
            when {$legacyUnit} in ('second', 'seconds') then ceiling({$legacyValue} / 60.0)
            when {$legacyUnit} in ('minute', 'minutes') then {$legacyValue}
            when {$legacyUnit} in ('hour', 'hours') then {$legacyValue} * 60
            when {$legacyUnit} in ('day', 'days') then {$legacyValue} * 1440
        end";
    }

    private function mysqlIntervalMinutesSql(): string
    {
        $compactValue = 'cast(substr(package_interval, 1, char_length(package_interval) - 1) as unsigned)';
        $compactUnit = 'right(package_interval, 1)';
        $legacyValue = "cast(substring_index(substring(package_interval, 7), '_', 1) as unsigned)";
        $legacyUnit = "substring(package_interval, char_length(concat('every_', substring_index(substring(package_interval, 7), '_', 1), '_')) + 1)";

        return "case
            when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 's' then ceiling({$compactValue} / 60)
            when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 'm' then {$compactValue}
            when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 'h' then {$compactValue} * 60
            when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and {$compactUnit} = 'd' then {$compactValue} * 1440
            when {$legacyUnit} in ('second', 'seconds') then ceiling({$legacyValue} / 60)
            when {$legacyUnit} in ('minute', 'minutes') then {$legacyValue}
            when {$legacyUnit} in ('hour', 'hours') then {$legacyValue} * 60
            when {$legacyUnit} in ('day', 'days') then {$legacyValue} * 1440
        end";
    }

    private function sqlServerValidIntervalSql(): string
    {
        return "(
            (
                package_interval like '[0-9]%[smhd]'
                and try_convert(bigint, left(package_interval, len(package_interval) - 1)) is not null
                and try_convert(bigint, left(package_interval, len(package_interval) - 1)) > 0
            )
            or (
                package_interval like 'every[_][0-9]%[_]%'
                and try_convert(bigint, substring(package_interval, 7, charindex('_', substring(package_interval, 7, len(package_interval))) - 1)) is not null
                and try_convert(bigint, substring(package_interval, 7, charindex('_', substring(package_interval, 7, len(package_interval))) - 1)) > 0
                and substring(package_interval, 7 + charindex('_', substring(package_interval, 7, len(package_interval))), len(package_interval)) in ('second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days')
            )
        )";
    }

    private function sqlServerIntervalMinutesSql(): string
    {
        $compactValue = 'try_convert(bigint, left(package_interval, len(package_interval) - 1))';
        $compactUnit = 'right(package_interval, 1)';
        $compactValid = "package_interval not like 'every[_]%' and {$compactValue} is not null and {$compactValue} > 0";
        $legacyValue = "try_convert(bigint, substring(package_interval, 7, charindex('_', substring(package_interval, 7, len(package_interval))) - 1))";
        $legacyUnit = "substring(package_interval, 7 + charindex('_', substring(package_interval, 7, len(package_interval))), len(package_interval))";

        $intervalMinutes = "case
            when {$legacyUnit} in ('second', 'seconds') then cast(ceiling({$legacyValue} / 60.0) as bigint)
            when {$legacyUnit} in ('minute', 'minutes') then {$legacyValue}
            when {$legacyUnit} in ('hour', 'hours') then {$legacyValue} * 60
            when {$legacyUnit} in ('day', 'days') then {$legacyValue} * 1440
            when {$compactValid} and {$compactUnit} = 's' then cast(ceiling({$compactValue} / 60.0) as bigint)
            when {$compactValid} and {$compactUnit} = 'm' then {$compactValue}
            when {$compactValid} and {$compactUnit} = 'h' then {$compactValue} * 60
            when {$compactValid} and {$compactUnit} = 'd' then {$compactValue} * 1440
        end";

        return "case
            when ({$intervalMinutes}) > 2147483647 then 2147483647
            else ({$intervalMinutes})
        end";
    }
}
