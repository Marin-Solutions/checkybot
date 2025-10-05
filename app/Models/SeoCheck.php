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
        'total_urls_crawled',
        'total_crawlable_urls',
        'sitemap_used',
        'robots_txt_checked',
        'started_at',
        'finished_at',
        'crawl_summary',
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

        // Calculate progress based on URLs crawled vs total crawlable URLs
        return min(100, (int) (($this->total_urls_crawled / $this->total_crawlable_urls) * 100));
    }

    public function getErrorsCountAttribute(): int
    {
        return $this->seoIssues()->where('severity', 'error')->count();
    }

    public function getWarningsCountAttribute(): int
    {
        return $this->seoIssues()->where('severity', 'warning')->count();
    }

    public function getNoticesCountAttribute(): int
    {
        return $this->seoIssues()->where('severity', 'notice')->count();
    }

    /**
     * Calculate the SEO Health Score based on Ahrefs-style scoring
     * Health Score (%) = (Total internal URLs without Errors ÷ Total internal URLs crawled) × 100
     * Warnings and Notices do not reduce score but are shown in the report
     */
    public function getHealthScoreAttribute(): float
    {
        if ($this->total_urls_crawled === 0) {
            return 0.0;
        }

        // Get count of URLs that have errors (status 4xx, 5xx, or SEO issues with severity 'error')
        $urlsWithErrors = $this->getUrlsWithErrorsCount();

        // Calculate health score: (URLs without errors / Total URLs) × 100
        $urlsWithoutErrors = $this->total_urls_crawled - $urlsWithErrors;
        $healthScore = ($urlsWithoutErrors / $this->total_urls_crawled) * 100;

        return round($healthScore, 1);
    }

    /**
     * Get count of URLs that have errors (either HTTP errors or SEO errors)
     */
    public function getUrlsWithErrorsCount(): int
    {
        // Count URLs with HTTP errors (4xx, 5xx)
        $httpErrorUrls = $this->crawlResults()
            ->where(function ($query) {
                $query->where('status_code', '>=', 400)
                    ->where('status_code', '<', 600);
            })
            ->count();

        // Count URLs with SEO errors (not including HTTP errors to avoid double counting)
        $seoErrorUrls = $this->seoIssues()
            ->where('severity', 'error')
            ->whereHas('seoCrawlResult', function ($query) {
                $query->where('status_code', '<', 400); // Only count SEO errors on successful HTTP responses
            })
            ->distinct('url')
            ->count('url');

        return $httpErrorUrls + $seoErrorUrls;
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
