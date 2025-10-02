<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoCrawlResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'seo_check_id',
        'url',
        'status_code',
        'canonical_url',
        'title',
        'meta_description',
        'h1',
        'internal_links',
        'external_links',
        'page_size_bytes',
        'html_size_bytes',
        'resource_sizes',
        'headers',
        'response_time_ms',
        'issues',
        'internal_link_count',
        'external_link_count',
        'image_count',
        'is_soft_404',
        'is_redirect_loop',
    ];

    protected $casts = [
        'internal_links' => 'array',
        'external_links' => 'array',
        'resource_sizes' => 'array',
        'headers' => 'array',
        'issues' => 'array',
        'response_time_ms' => 'decimal:2',
        'is_soft_404' => 'boolean',
        'is_redirect_loop' => 'boolean',
    ];

    public function seoCheck(): BelongsTo
    {
        return $this->belongsTo(SeoCheck::class);
    }

    public function isSuccess(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }

    public function isRedirect(): bool
    {
        return $this->status_code >= 300 && $this->status_code < 400;
    }

    public function isClientError(): bool
    {
        return $this->status_code >= 400 && $this->status_code < 500;
    }

    public function isServerError(): bool
    {
        return $this->status_code >= 500 && $this->status_code < 600;
    }

    public function isError(): bool
    {
        return $this->isClientError() || $this->isServerError();
    }

    public function hasIssues(): bool
    {
        return ! empty($this->issues);
    }

    public function getPageSizeInKb(): float
    {
        return $this->page_size_bytes ? round($this->page_size_bytes / 1024, 2) : 0;
    }

    public function getHtmlSizeInKb(): float
    {
        return $this->html_size_bytes ? round($this->html_size_bytes / 1024, 2) : 0;
    }

    public function getResponseTimeInSeconds(): float
    {
        return $this->response_time_ms ? round($this->response_time_ms / 1000, 3) : 0;
    }
}
