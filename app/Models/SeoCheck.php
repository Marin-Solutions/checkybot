<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SeoCheck extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const FAILURE_REASON_NO_CRAWLABLE_URLS = 'no_crawlable_urls';

    public const FAILURE_REASON_STARTUP = 'startup';

    public const FAILURE_REASON_TIMEOUT = 'timeout';

    public const FAILURE_REASON_STUCK_RUN_EXPIRY = 'stuck_run_expiry';

    public const FAILURE_REASON_OTHER = 'other';

    private const STARTUP_FAILURE_REASONS = [
        'manual_startup_failed',
        'scheduled_startup_failed',
        'manual_dispatch_failed',
    ];

    private const TIMEOUT_LIKE_PATTERNS = [
        '%timeout%',
        '%Timeout%',
        '%timed out%',
        '%Timed out%',
    ];

    protected $fillable = [
        'website_id',
        'status',
        'progress',
        'total_urls_crawled',
        'total_crawlable_urls',
        'sitemap_used',
        'robots_txt_checked',
        'started_at',
        'finished_at',
        'crawl_summary',
        'failure_summary',
        'failure_context',
        'computed_errors_count',
        'computed_warnings_count',
        'computed_notices_count',
        'computed_http_errors_count',
        'computed_health_score',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'crawl_summary' => 'array',
        'failure_context' => 'array',
        'sitemap_used' => 'boolean',
        'robots_txt_checked' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function crawlResults(): HasMany
    {
        return $this->hasMany(SeoCrawlResult::class);
    }

    public function seoIssues(): HasMany
    {
        return $this->hasMany(SeoIssue::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function getRunSourceAttribute(): string
    {
        $crawlSummary = $this->crawl_summary ?? [];

        if (($crawlSummary['is_scheduled'] ?? false) === true) {
            return 'scheduled';
        }

        return 'manual';
    }

    public function getRunSourceLabelAttribute(): string
    {
        return match ($this->run_source) {
            'scheduled' => 'Scheduled',
            default => 'Manual',
        };
    }

    public function getFailureReasonAttribute(): ?string
    {
        if (! $this->isFailed()) {
            return null;
        }

        $failureContext = $this->failure_context ?? [];
        $crawlSummary = $this->crawl_summary ?? [];
        $reason = $failureContext['failure_reason'] ?? $crawlSummary['failure_reason'] ?? null;

        if ($reason === self::FAILURE_REASON_NO_CRAWLABLE_URLS) {
            return self::FAILURE_REASON_NO_CRAWLABLE_URLS;
        }

        if (in_array($reason, self::STARTUP_FAILURE_REASONS, true)) {
            return self::FAILURE_REASON_STARTUP;
        }

        if (($failureContext['expired_by'] ?? null) || str_starts_with((string) $this->failure_summary, 'SEO check expired after')) {
            return self::FAILURE_REASON_STUCK_RUN_EXPIRY;
        }

        $failureText = strtolower(implode(' ', array_filter([
            $this->failure_summary,
            $failureContext['exception'] ?? null,
            $failureContext['exception_class'] ?? null,
            $failureContext['exception_message'] ?? null,
        ])));

        if (Str::contains($failureText, ['timeout', 'timed out'])) {
            return self::FAILURE_REASON_TIMEOUT;
        }

        return self::FAILURE_REASON_OTHER;
    }

    public function getFailureReasonLabelAttribute(): ?string
    {
        return match ($this->failure_reason) {
            self::FAILURE_REASON_NO_CRAWLABLE_URLS => 'No crawlable URLs',
            self::FAILURE_REASON_STARTUP => 'Startup failed',
            self::FAILURE_REASON_TIMEOUT => 'Timeout',
            self::FAILURE_REASON_STUCK_RUN_EXPIRY => 'Stuck-run expiry',
            self::FAILURE_REASON_OTHER => 'Other failure',
            default => null,
        };
    }

    public static function failureReasonFilterOptions(): array
    {
        return [
            self::FAILURE_REASON_STARTUP => 'Startup failed',
            self::FAILURE_REASON_NO_CRAWLABLE_URLS => 'No crawlable URLs',
            self::FAILURE_REASON_TIMEOUT => 'Timeout',
            self::FAILURE_REASON_STUCK_RUN_EXPIRY => 'Stuck-run expiry',
            self::FAILURE_REASON_OTHER => 'Other failure',
        ];
    }

    public static function applyFailureReasonFilter(Builder $query, string $reason): Builder
    {
        return match ($reason) {
            self::FAILURE_REASON_NO_CRAWLABLE_URLS => $query
                ->where('status', self::STATUS_FAILED)
                ->where(function (Builder $query): void {
                    $query
                        ->where('failure_context->failure_reason', self::FAILURE_REASON_NO_CRAWLABLE_URLS)
                        ->orWhere('crawl_summary->failure_reason', self::FAILURE_REASON_NO_CRAWLABLE_URLS);
                }),
            self::FAILURE_REASON_STARTUP => $query
                ->where('status', self::STATUS_FAILED)
                ->where(function (Builder $query): void {
                    $query
                        ->whereIn('failure_context->failure_reason', self::STARTUP_FAILURE_REASONS)
                        ->orWhereIn('crawl_summary->failure_reason', self::STARTUP_FAILURE_REASONS);
                }),
            self::FAILURE_REASON_TIMEOUT => $query
                ->where('status', self::STATUS_FAILED)
                ->where(function (Builder $query): void {
                    foreach ([
                        'failure_summary',
                        'failure_context->exception',
                        'failure_context->exception_class',
                        'failure_context->exception_message',
                    ] as $column) {
                        foreach (self::TIMEOUT_LIKE_PATTERNS as $pattern) {
                            $query->orWhere($column, 'like', $pattern);
                        }
                    }
                }),
            self::FAILURE_REASON_STUCK_RUN_EXPIRY => $query
                ->where('status', self::STATUS_FAILED)
                ->where(function (Builder $query): void {
                    $query
                        ->whereNotNull('failure_context->expired_by')
                        ->orWhere('failure_summary', 'like', 'SEO check expired after%');
                }),
            self::FAILURE_REASON_OTHER => $query
                ->where('status', self::STATUS_FAILED)
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('failure_context->failure_reason')
                        ->orWhereNotIn('failure_context->failure_reason', [
                            self::FAILURE_REASON_NO_CRAWLABLE_URLS,
                            ...self::STARTUP_FAILURE_REASONS,
                        ]);
                })
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('crawl_summary->failure_reason')
                        ->orWhereNotIn('crawl_summary->failure_reason', [
                            self::FAILURE_REASON_NO_CRAWLABLE_URLS,
                            ...self::STARTUP_FAILURE_REASONS,
                        ]);
                })
                ->whereNull('failure_context->expired_by')
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('failure_summary')
                        ->orWhere(function (Builder $query): void {
                            $query->where('failure_summary', 'not like', 'SEO check expired after%');

                            foreach (self::TIMEOUT_LIKE_PATTERNS as $pattern) {
                                $query->where('failure_summary', 'not like', $pattern);
                            }
                        });
                })
                ->where(function (Builder $query): void {
                    foreach ([
                        'failure_context->exception',
                        'failure_context->exception_class',
                        'failure_context->exception_message',
                    ] as $column) {
                        $query->where(function (Builder $query) use ($column): void {
                            $query
                                ->whereNull($column)
                                ->orWhere(function (Builder $query) use ($column): void {
                                    foreach (self::TIMEOUT_LIKE_PATTERNS as $pattern) {
                                        $query->where($column, 'not like', $pattern);
                                    }
                                });
                        });
                    }
                }),
            default => $query,
        };
    }

    public function getDurationInSeconds(): ?int
    {
        if ($this->started_at && $this->finished_at) {
            return $this->started_at->diffInSeconds($this->finished_at);
        }

        return null;
    }

    public function getProgressPercentage(): int
    {
        if (! $this->isRunning() || $this->total_crawlable_urls === 0) {
            return 0;
        }

        // For dynamic discovery, use a more stable progress calculation
        $crawlStrategy = $this->crawl_summary['crawl_strategy'] ?? 'dynamic_discovery';

        if ($crawlStrategy === 'dynamic_discovery') {
            // For dynamic discovery, use the current total but cap progress at 95% until completed
            $progress = ($this->total_urls_crawled / $this->total_crawlable_urls) * 100;

            // Cap at 95% until the crawl is actually completed to avoid showing 100% prematurely
            if ($this->status === 'running') {
                return min(95, (int) $progress);
            } else {
                return min(100, (int) $progress);
            }
        } else {
            // For sitemap preload, use the exact total
            return min(100, (int) (($this->total_urls_crawled / $this->total_crawlable_urls) * 100));
        }
    }

    public function getErrorsCountAttribute(): int
    {
        // Use computed column if available (most efficient)
        if (isset($this->attributes['computed_errors_count'])) {
            return (int) $this->attributes['computed_errors_count'];
        }

        // Use pre-calculated count if available (from withCount)
        if (isset($this->attributes['errors_count'])) {
            return (int) $this->attributes['errors_count'];
        }

        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('seoIssues')) {
            return $this->seoIssues->where('severity', 'error')->count();
        }

        // Fallback to database query
        return $this->seoIssues()->where('severity', 'error')->count();
    }

    public function getWarningsCountAttribute(): int
    {
        // Use computed column if available (most efficient)
        if (isset($this->attributes['computed_warnings_count'])) {
            return (int) $this->attributes['computed_warnings_count'];
        }

        // Use pre-calculated count if available (from withCount)
        if (isset($this->attributes['warnings_count'])) {
            return (int) $this->attributes['warnings_count'];
        }

        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('seoIssues')) {
            return $this->seoIssues->where('severity', 'warning')->count();
        }

        return $this->seoIssues()->where('severity', 'warning')->count();
    }

    public function getNoticesCountAttribute(): int
    {
        // Use computed column if available (most efficient)
        if (isset($this->attributes['computed_notices_count'])) {
            return (int) $this->attributes['computed_notices_count'];
        }

        // Use pre-calculated count if available (from withCount)
        if (isset($this->attributes['notices_count'])) {
            return (int) $this->attributes['notices_count'];
        }

        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('seoIssues')) {
            return $this->seoIssues->where('severity', 'notice')->count();
        }

        return $this->seoIssues()->where('severity', 'notice')->count();
    }

    /**
     * Calculate the SEO Health Score based on Ahrefs-style scoring
     * Health Score (%) = (Total internal URLs without Errors ÷ Total internal URLs crawled) × 100
     * Errors (both HTTP and SEO) reduce the score, Warnings and Notices do not reduce score but are shown in the report
     */
    public function getHealthScoreAttribute(): float
    {
        if ($this->total_urls_crawled === 0) {
            return 0.0;
        }

        // Use computed column if available (most efficient)
        if (isset($this->attributes['computed_health_score'])) {
            return (float) $this->attributes['computed_health_score'];
        }

        // Use pre-calculated counts if available to avoid expensive queries
        $httpErrorCount = isset($this->attributes['http_errors_count'])
            ? (int) $this->attributes['http_errors_count']
            : 0;

        $seoErrorCount = isset($this->attributes['errors_count'])
            ? (int) $this->attributes['errors_count']
            : 0;

        $urlsWithErrors = $httpErrorCount + $seoErrorCount;

        // If we have pre-calculated counts, use them directly
        if ($httpErrorCount > 0 || $seoErrorCount > 0) {
            $urlsWithoutErrors = $this->total_urls_crawled - $urlsWithErrors;
            $healthScore = ($urlsWithoutErrors / $this->total_urls_crawled) * 100;

            return round($healthScore, 1);
        }

        // Fallback to the expensive method only if pre-calculated counts are not available
        $urlsWithErrors = $this->getUrlsWithErrorsCount();
        $urlsWithoutErrors = $this->total_urls_crawled - $urlsWithErrors;
        $healthScore = ($urlsWithoutErrors / $this->total_urls_crawled) * 100;

        return round($healthScore, 1);
    }

    /**
     * Get count of URLs that have errors (either HTTP errors or SEO errors)
     */
    public function getUrlsWithErrorsCount(): int
    {
        // Use computed columns if available (most efficient)
        $httpErrorCount = isset($this->attributes['computed_http_errors_count'])
            ? (int) $this->attributes['computed_http_errors_count']
            : 0;

        $seoErrorCount = isset($this->attributes['computed_errors_count'])
            ? (int) $this->attributes['computed_errors_count']
            : 0;

        if ($httpErrorCount > 0 || $seoErrorCount > 0) {
            return $httpErrorCount + $seoErrorCount;
        }

        // Use pre-calculated counts if available (from withCount)
        $httpErrorCount = isset($this->attributes['http_errors_count'])
            ? (int) $this->attributes['http_errors_count']
            : 0;

        $seoErrorCount = isset($this->attributes['errors_count'])
            ? (int) $this->attributes['errors_count']
            : 0;

        if ($httpErrorCount > 0 || $seoErrorCount > 0) {
            return $httpErrorCount + $seoErrorCount;
        }

        // Use loaded relationships if available to avoid N+1 queries
        if ($this->relationLoaded('crawlResults') && $this->relationLoaded('seoIssues')) {
            // Count URLs with HTTP errors (4xx, 5xx) from loaded relationship
            $httpErrorUrls = $this->crawlResults
                ->where('status_code', '>=', 400)
                ->where('status_code', '<', 600)
                ->count();

            // Count URLs with SEO errors from loaded relationship
            $seoErrorUrls = $this->seoIssues
                ->where('severity', 'error')
                ->unique('url')
                ->count();

            return $httpErrorUrls + $seoErrorUrls;
        }

        // Fallback to database queries if relationships not loaded
        $httpErrorCount = $this->crawlResults()
            ->where('status_code', '>=', 400)
            ->where('status_code', '<', 600)
            ->count();

        $seoErrorCount = $this->seoIssues()
            ->where('severity', 'error')
            ->distinct('url')
            ->count('url');

        return $httpErrorCount + $seoErrorCount;
    }

    /**
     * Get health score as a formatted string with percentage
     */
    public function getHealthScoreFormattedAttribute(): string
    {
        return $this->getHealthScoreAttribute().'%';
    }

    /**
     * Get health score color for UI display
     */
    public function getHealthScoreColorAttribute(): string
    {
        $score = $this->getHealthScoreAttribute();

        if ($score >= 90) {
            return 'success'; // Green - Excellent (90-100%)
        } elseif ($score >= 70) {
            return 'warning'; // Yellow - Good (70-89%)
        } elseif ($score >= 31) {
            return 'info'; // Orange/Blue - Fair (31-69%) - using info as closest to orange
        } else {
            return 'danger'; // Red - Poor (0-30%)
        }
    }

    /**
     * Get health score status label
     */
    public function getHealthScoreStatusAttribute(): string
    {
        $score = $this->getHealthScoreAttribute();

        if ($score >= 90) {
            return 'Excellent';
        } elseif ($score >= 70) {
            return 'Good';
        } elseif ($score >= 31) {
            return 'Fair';
        } else {
            return 'Poor';
        }
    }
}
