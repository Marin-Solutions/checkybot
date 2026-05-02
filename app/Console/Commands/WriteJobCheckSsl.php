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
                ->orWhereIn(
                    'ssl_expiry_date',
                    collect(self::REMINDER_DAYS)
                        ->map(fn (int $days): string => $today->copy()->addDays($days)->toDateString())
                        ->unique()
                        ->all()
                );
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

        return match (Website::query()->getConnection()->getDriverName()) {
            'sqlite' => [
                "package_interval GLOB '[1-9]*[mhd]'"
                    ." AND substr(package_interval, 1, length(package_interval) - 1) NOT GLOB '*[^0-9]*'"
                    ." AND datetime(last_heartbeat_at, '+' || (CAST(substr(package_interval, 1, length(package_interval) - 1) AS INTEGER) * CASE substr(package_interval, -1) WHEN 'm' THEN 1 WHEN 'h' THEN 60 WHEN 'd' THEN 1440 END) || ' minutes') <= ?",
                [$now],
            ],
            'pgsql' => [
                "package_interval ~ '^[1-9][0-9]*[mhd]$'"
                    ." AND date_trunc('second', last_heartbeat_at) + ((substring(package_interval from 1 for char_length(package_interval) - 1)::integer * CASE right(package_interval, 1) WHEN 'm' THEN 1 WHEN 'h' THEN 60 WHEN 'd' THEN 1440 END) * interval '1 minute') <= ?",
                [$now],
            ],
            'sqlsrv' => [
                "package_interval LIKE '[1-9]%[mhd]'"
                    .' AND PATINDEX(\'%[^0-9]%\', LEFT(package_interval, LEN(package_interval) - 1)) = 0'
                    ." AND DATEADD(minute, CAST(LEFT(package_interval, LEN(package_interval) - 1) AS int) * CASE RIGHT(package_interval, 1) WHEN 'm' THEN 1 WHEN 'h' THEN 60 WHEN 'd' THEN 1440 END, last_heartbeat_at) <= ?",
                [$now],
            ],
            default => [
                "package_interval REGEXP '^[1-9][0-9]*[mhd]$'"
                    ." AND TIMESTAMPADD(MINUTE, CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * CASE RIGHT(package_interval, 1) WHEN 'm' THEN 1 WHEN 'h' THEN 60 WHEN 'd' THEN 1440 END, last_heartbeat_at) <= ?",
                [$now],
            ],
        };
    }
}
