<?php

namespace App\Console\Commands;

use App\Models\ServerInformationHistory;
use App\Models\ServerLogFileHistory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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
    protected $description = 'Prune server metric history and uploaded server log files by retention windows';

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

        $deletedFileHistories = $this->deleteExpiredServerLogFileHistories($chunkSize, $now);

        if ($this->serverLogFileRetentionDays() > 0) {
            $this->comment(
                "Purged {$deletedFileHistories} uploaded server log files older than {$this->serverLogFileRetentionDays()} days."
            );
        }
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

    private function deleteExpiredServerLogFileHistories(int $chunkSize, Carbon $now): int
    {
        $retentionDays = $this->serverLogFileRetentionDays();

        if ($retentionDays <= 0) {
            return 0;
        }

        $query = ServerLogFileHistory::query()
            ->where('created_at', '<', $now->copy()->subDays($retentionDays));

        $deleted = 0;

        do {
            /** @var Collection<int, ServerLogFileHistory> $histories */
            $histories = (clone $query)
                ->reorder('id')
                ->limit($chunkSize)
                ->get(['id', 'log_file_name']);

            if ($histories->isEmpty()) {
                break;
            }

            $paths = $histories
                ->pluck('log_file_name')
                ->filter(fn (?string $path): bool => $this->isManagedServerLogFilePath($path))
                ->values()
                ->all();

            if ($paths !== []) {
                Storage::delete($paths);
            }

            $deleted += ServerLogFileHistory::query()
                ->whereKey($histories->pluck('id'))
                ->delete();
        } while ($histories->count() === $chunkSize);

        return $deleted;
    }

    private function isManagedServerLogFilePath(?string $path): bool
    {
        return is_string($path)
            && $path !== ''
            && str_starts_with($path, 'ServerLogFiles/')
            && ! str_contains($path, '..');
    }

    private function serverLogFileRetentionDays(): int
    {
        return (int) config('monitor.server_log_file_retention_days', 30);
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
