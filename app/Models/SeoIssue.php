<?php

namespace App\Models;

use App\Enums\SeoIssueSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'seo_check_id',
        'seo_crawl_result_id',
        'type',
        'severity',
        'url',
        'title',
        'description',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'severity' => SeoIssueSeverity::class,
    ];

    public function seoCheck(): BelongsTo
    {
        return $this->belongsTo(SeoCheck::class);
    }

    public function seoCrawlResult(): BelongsTo
    {
        return $this->belongsTo(SeoCrawlResult::class);
    }

    public function crawlResult(): BelongsTo
    {
        return $this->seoCrawlResult();
    }

    public function isError(): bool
    {
        return $this->severity === SeoIssueSeverity::Error;
    }

    public function isWarning(): bool
    {
        return $this->severity === SeoIssueSeverity::Warning;
    }

    public function isNotice(): bool
    {
        return $this->severity === SeoIssueSeverity::Notice;
    }

    public function getSeverityLabel(): string
    {
        return $this->severity->getLabel();
    }

    public function getSeverityColor(): string
    {
        return $this->severity->getColor();
    }

    public function getPriority(): int
    {
        return $this->severity->getPriority();
    }
}
