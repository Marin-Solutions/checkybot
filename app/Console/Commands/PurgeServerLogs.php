<?php

namespace App\Console\Commands;

use App\Models\ServerInformationHistory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class PurgeServerLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:purge-server-logs {--chunk=1000 : Number of matching rows to delete per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune server metric history into 10-minute and hourly retention windows';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // For the last 24 hours we will keep the data of every minute
        // For data older than 24 hours but younger than 7 days we will keep the data of every ten minutes
        // For data older than 7 days we will keep the data of every hour

        $chunkSize = max(1, (int) $this->option('chunk'));
        $now = now();

        $midRangeQuery = ServerInformationHistory::query()
            ->where('created_at', '<', $now->copy()->subHours(24))
            ->where('created_at', '>=', $now->copy()->subDays(7));
        $this->whereCreatedMinuteIsNotMultipleOf($midRangeQuery, 10);

        $deletedMidRange = $this->deleteInChunks($midRangeQuery, $chunkSize);

        $oldQuery = ServerInformationHistory::query()
            ->where('created_at', '<', $now->copy()->subDays(7));
        $this->whereCreatedMinuteIsNotMultipleOf($oldQuery, 60);

        $deletedOld = $this->deleteInChunks($oldQuery, $chunkSize);

        $this->comment("Purged {$deletedMidRange} mid-range logs and {$deletedOld} old logs.");
    }

    private function deleteInChunks(Builder $query, int $chunkSize): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)
                ->reorder('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += ServerInformationHistory::query()
                ->whereKey($ids)
                ->delete();
        } while ($ids->count() === $chunkSize);

        return $deleted;
    }

    private function whereCreatedMinuteIsNotMultipleOf(Builder $query, int $multiple): Builder
    {
        [$sql, $bindings] = $this->createdMinuteModuloPredicate($multiple);

        return $query->whereRaw($sql, $bindings);
    }

    /**
     * @return array{0: string, 1: array<int, int>}
     */
    private function createdMinuteModuloPredicate(int $multiple): array
    {
        return match (ServerInformationHistory::query()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => ['MOD(MINUTE(created_at), ?) != 0', [$multiple]],
            'pgsql' => ['MOD(EXTRACT(MINUTE FROM created_at)::integer, ?) != 0', [$multiple]],
            'sqlite' => ["(CAST(strftime('%M', created_at) AS INTEGER) % ?) != 0", [$multiple]],
            'sqlsrv' => ['(DATEPART(minute, created_at) % ?) != 0', [$multiple]],
            default => throw new RuntimeException('Unsupported database driver for server log purging.'),
        };
    }
}
