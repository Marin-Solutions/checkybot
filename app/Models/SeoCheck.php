<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoCheck extends Model
{
    use HasFactory;

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
        return $this->status === 'completed';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
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
        return $this->getHealthScoreAttribute() . '%';
    }

    /**
     * Get health score color for UI display
     */
    public function getHealthScoreColorAttribute(): string
    {
        $score = $this->getHealthScoreAttribute();

        if ($score >= 90) {
            return 'success'; // Green
        } elseif ($score >= 70) {
            return 'warning'; // Yellow
        } else {
            return 'danger'; // Red
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
        } elseif ($score >= 50) {
            return 'Fair';
        } else {
            return 'Poor';
        }
    }
}
