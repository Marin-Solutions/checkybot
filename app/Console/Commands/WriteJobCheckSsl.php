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
        return PackageIntervalDueExpression::build(Website::query()->getConnection());
    }
}
