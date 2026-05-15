<?php

namespace App\Console\Commands;

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\Website;
use App\Support\PackageIntervalDueExpression;
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
                        $this->whereManualSslOnlyCheck($query);
                        $this->whereManualSslCheckIsDue($query);
                    })
                    ->orWhere(function (Builder $query): void {
                        $this->whereNotScheduledSslOnlyCheck($query);
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

    private function whereManualSslOnlyCheck(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->where('source', '!=', 'package')
                    ->orWhereNull('source');
            })
            ->where('uptime_check', false)
            ->whereNotNull('uptime_interval');
    }

    private function whereNotScheduledSslOnlyCheck(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->where('uptime_check', true)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('source', 'package')
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('package_interval')
                                ->orWhere('package_interval', '');
                        });
                })
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where(function (Builder $query): void {
                            $query
                                ->where('source', '!=', 'package')
                                ->orWhereNull('source');
                        })
                        ->whereNull('uptime_interval');
                });
        });
    }

    private function wherePackageSslCheckIsDue(Builder $query): void
    {
        [$intervalDueSql, $bindings] = $this->packageIntervalDueExpression();
        $latestScheduledAtSql = $this->latestScheduledLogAtSql();

        $query->where(function (Builder $query) use ($intervalDueSql, $bindings, $latestScheduledAtSql): void {
            $query
                ->whereRaw("{$latestScheduledAtSql} is null")
                ->orWhereRaw($intervalDueSql, $bindings);
        });
    }

    private function whereManualSslCheckIsDue(Builder $query): void
    {
        $latestScheduledAtSql = $this->latestScheduledLogAtSql();

        $query->where(function (Builder $query) use ($latestScheduledAtSql): void {
            $query
                ->whereRaw("{$latestScheduledAtSql} is null")
                ->orWhereRaw($this->manualIntervalDueExpression($latestScheduledAtSql), [now()->startOfMinute()->toDateTimeString()]);
        });
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function packageIntervalDueExpression(): array
    {
        return PackageIntervalDueExpression::build(Website::query()->getConnection(), anchorColumn: $this->latestScheduledLogAtSql());
    }

    private function manualIntervalDueExpression(string $anchorSql): string
    {
        return match (Website::query()->getConnection()->getDriverName()) {
            'sqlite' => "datetime(strftime('%Y-%m-%d %H:%M:00', {$anchorSql}), '+' || websites.uptime_interval || ' minutes') <= ?",
            'pgsql' => "date_trunc('minute', {$anchorSql}) + (websites.uptime_interval * interval '1 minute') <= ?",
            'sqlsrv' => "DATEADD(minute, websites.uptime_interval, DATEADD(minute, DATEDIFF(minute, 0, {$anchorSql}), 0)) <= ?",
            default => "DATE_ADD(DATE_FORMAT({$anchorSql}, '%Y-%m-%d %H:%i:00'), INTERVAL websites.uptime_interval MINUTE) <= ?",
        };
    }

    private function latestScheduledLogAtSql(): string
    {
        return '(select max(website_log_history.created_at) from website_log_history where website_log_history.website_id = websites.id and website_log_history.is_on_demand = 0)';
    }
}
