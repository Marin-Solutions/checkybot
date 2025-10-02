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
        'health_score',
        'total_urls_crawled',
        'errors_found',
        'warnings_found',
        'notices_found',
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
        if (! $this->isRunning() || $this->total_urls_crawled === 0) {
            return 0;
        }

        // Estimate progress based on URLs crawled (this would need to be updated during crawling)
        return min(100, (int) (($this->total_urls_crawled / 1000) * 100)); // Rough estimate
    }
}
