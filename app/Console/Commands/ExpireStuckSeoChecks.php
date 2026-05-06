<?php

namespace App\Console\Commands;

use App\Models\SeoCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExpireStuckSeoChecks extends Command
{
    protected $signature = 'seo:expire-stuck
        {--pending-minutes=60 : Fail pending SEO checks older than this many minutes}
        {--running-minutes=30 : Fail running SEO checks older than this many minutes}';

    protected $description = 'Mark abandoned pending or running SEO checks as failed';

    public function handle(): int
    {
        $pendingMinutes = $this->positiveIntegerOption('pending-minutes');
        $runningMinutes = $this->positiveIntegerOption('running-minutes');

        $pendingCutoff = now()->subMinutes($pendingMinutes);
        $runningCutoff = now()->subMinutes($runningMinutes);

        $expiredPending = $this->expirePendingChecks($pendingCutoff, $pendingMinutes);
        $expiredRunning = $this->expireRunningChecks($runningCutoff, $runningMinutes);
        $totalExpired = $expiredPending + $expiredRunning;

        $this->info("Expired {$totalExpired} stuck SEO checks ({$expiredPending} pending, {$expiredRunning} running).");

        return self::SUCCESS;
    }

    private function expirePendingChecks(Carbon $cutoff, int $thresholdMinutes): int
    {
        return $this->expireChecks(
            SeoCheck::STATUS_PENDING,
            $thresholdMinutes,
            SeoCheck::query()
                ->where('status', SeoCheck::STATUS_PENDING)
                ->where('created_at', '<=', $cutoff)
        );
    }

    private function expireRunningChecks(Carbon $cutoff, int $thresholdMinutes): int
    {
        return $this->expireChecks(
            SeoCheck::STATUS_RUNNING,
            $thresholdMinutes,
            SeoCheck::query()
                ->where('status', SeoCheck::STATUS_RUNNING)
                ->where(function ($query) use ($cutoff): void {
                    $query
                        ->where('started_at', '<=', $cutoff)
                        ->orWhere(function ($query) use ($cutoff): void {
                            $query
                                ->whereNull('started_at')
                                ->where('created_at', '<=', $cutoff);
                        });
                })
        );
    }

    private function expireChecks(string $status, int $thresholdMinutes, \Illuminate\Database\Eloquent\Builder $query): int
    {
        $expired = 0;

        $query
            ->with('website:id,url')
            ->orderBy('id')
            ->chunkById(100, function ($seoChecks) use (&$expired, $status, $thresholdMinutes): void {
                foreach ($seoChecks as $seoCheck) {
                    $summary = $this->failureSummary($status, $thresholdMinutes);

                    $updated = SeoCheck::query()
                        ->whereKey($seoCheck->id)
                        ->where('status', $status)
                        ->update([
                            'status' => SeoCheck::STATUS_FAILED,
                            'finished_at' => now(),
                            'failure_summary' => $summary,
                            'failure_context' => $this->failureContext($seoCheck, $status, $thresholdMinutes),
                        ]);

                    if ($updated === 0) {
                        continue;
                    }

                    $expired++;

                    Log::warning('Expired stuck SEO check.', [
                        'seo_check_id' => $seoCheck->id,
                        'website_id' => $seoCheck->website_id,
                        'previous_status' => $status,
                        'threshold_minutes' => $thresholdMinutes,
                    ]);
                }
            });

        return $expired;
    }

    private function failureSummary(string $status, int $thresholdMinutes): string
    {
        return match ($status) {
            SeoCheck::STATUS_PENDING => "SEO check expired after waiting in the queue for more than {$thresholdMinutes} minutes.",
            SeoCheck::STATUS_RUNNING => "SEO check expired after running for more than {$thresholdMinutes} minutes without finishing.",
            default => "SEO check expired after staying {$status} for more than {$thresholdMinutes} minutes.",
        };
    }

    private function failureContext(SeoCheck $seoCheck, string $status, int $thresholdMinutes): array
    {
        return array_filter([
            'expired_by' => static::class,
            'previous_status' => $status,
            'threshold_minutes' => $thresholdMinutes,
            'website_url' => $seoCheck->website?->url,
            'started_at' => $seoCheck->started_at?->toIso8601String(),
            'created_at' => $seoCheck->created_at?->toIso8601String(),
            'total_urls_crawled' => $seoCheck->total_urls_crawled ?? 0,
            'total_crawlable_urls' => $seoCheck->total_crawlable_urls ?? 0,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function positiveIntegerOption(string $name): int
    {
        $value = (int) $this->option($name);

        if ($value < 1) {
            $this->fail("The --{$name} option must be at least 1.");
        }

        return $value;
    }
}
