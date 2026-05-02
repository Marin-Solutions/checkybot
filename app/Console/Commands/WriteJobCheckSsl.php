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

    private const PACKAGE_INTERVALS = [
        '1m' => 1,
        '5m' => 5,
        '10m' => 10,
        '15m' => 15,
        '30m' => 30,
        '1h' => 60,
        '6h' => 360,
        '12h' => 720,
        '1d' => 1440,
    ];

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
                        ->flatMap(function (int $days) use ($today): array {
                            $date = $today->copy()->addDays($days);

                            return [
                                $date->toDateString(),
                                $date->startOfDay()->toDateTimeString(),
                            ];
                        })
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
        $now = now();

        $query->where(function (Builder $query) use ($now): void {
            $query
                ->whereNull('last_heartbeat_at')
                ->orWhere(function (Builder $query) use ($now): void {
                    foreach (self::PACKAGE_INTERVALS as $interval => $minutes) {
                        $query->orWhere(function (Builder $query) use ($interval, $minutes, $now): void {
                            $query
                                ->where('package_interval', $interval)
                                ->where('last_heartbeat_at', '<=', $now->copy()->subMinutes($minutes)->toDateTimeString());
                        });
                    }
                });
        });
    }
}
