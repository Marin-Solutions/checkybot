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

    public function getAffectedUrls(): array
    {
        $data = $this->data ?? [];
        $urls = [
            [
                'label' => 'Flagged page',
                'url' => $this->url,
            ],
        ];

        foreach ([
            'broken_url' => 'Broken target',
            'canonical_url' => 'Canonical URL',
            'redirect_to' => 'Redirect target',
        ] as $key => $label) {
            if (filled($data[$key] ?? null)) {
                $urls[] = [
                    'label' => $label,
                    'url' => $data[$key],
                ];
            }
        }

        foreach ([
            'duplicate_urls' => 'Duplicate page',
            'affected_urls' => 'Affected page',
        ] as $key => $label) {
            foreach ((array) ($data[$key] ?? []) as $url) {
                if (filled($url)) {
                    $urls[] = [
                        'label' => $label,
                        'url' => $url,
                    ];
                }
            }
        }

        return collect($urls)
            ->unique('url')
            ->values()
            ->all();
    }

    public function getEvidenceItems(): array
    {
        $data = $this->data ?? [];
        $crawlResult = $this->seoCrawlResult;

        $items = [
            ['label' => 'Issue', 'value' => $this->title],
            ['label' => 'Description', 'value' => $this->description],
        ];

        if ($crawlResult) {
            $items[] = ['label' => 'HTTP status', 'value' => $crawlResult->status_code ?: 'Not captured'];
            $items[] = ['label' => 'Response time', 'value' => $crawlResult->response_time_ms !== null ? "{$crawlResult->response_time_ms}ms" : 'Not captured'];
            $items[] = ['label' => 'Page title', 'value' => $crawlResult->title ?: 'Missing'];
            $items[] = ['label' => 'Meta description', 'value' => $crawlResult->meta_description ?: 'Missing'];
            $items[] = ['label' => 'Internal links', 'value' => $crawlResult->internal_link_count ?? 'Not captured'];
        }

        foreach ($data as $key => $value) {
            if ($key === 'recommendation') {
                continue;
            }

            $items[] = [
                'label' => str($key)->replace('_', ' ')->headline()->toString(),
                'value' => $this->formatDetailValue($value),
            ];
        }

        return collect($items)
            ->filter(fn (array $item): bool => filled($item['value']))
            ->values()
            ->all();
    }

    public function getStoredDataForDisplay(): array
    {
        return collect($this->data ?? [])
            ->map(fn (mixed $value): string => $this->formatDetailValue($value))
            ->all();
    }

    public function getFixGuidance(): array
    {
        $storedRecommendation = $this->data['recommendation'] ?? null;

        $guidance = match ($this->type) {
            'broken_internal_link' => [
                'Update or remove the link on the flagged page.',
                'If the target should exist, restore it or add a redirect to the correct destination.',
                'Run the SEO check again after the target returns a successful HTTP status.',
            ],
            'redirect_loop' => [
                'Fix the redirect rule so the source URL resolves to a different final URL.',
                'Check CMS, web server, and CDN redirect rules for circular matches.',
            ],
            'canonical_error', 'canonical_issue' => [
                'Point the canonical tag at a crawlable 200 URL.',
                'Use an absolute canonical URL and keep it consistent with the preferred page version.',
            ],
            'mixed_content' => [
                'Load every image, script, and stylesheet on this HTTPS page over HTTPS.',
                'Replace hard-coded http:// asset URLs or proxy assets through a secure host.',
            ],
            'http_not_redirected' => [
                'Add a permanent redirect from HTTP to the HTTPS version of this URL.',
                'Confirm the HTTPS page returns 200 and is the version indexed by search engines.',
            ],
            'missing_meta_description' => [
                'Add a unique meta description that summarizes the page for search results.',
                'Keep it specific to the page and avoid reusing the same copy across many URLs.',
            ],
            'missing_h1' => [
                'Add one visible H1 that clearly describes the page topic.',
                'Keep the H1 aligned with the page title and primary search intent.',
            ],
            'duplicate_h1' => [
                'Keep one primary H1 and demote secondary headings to H2 or lower.',
                'Check shared templates that may be adding an extra H1.',
            ],
            'large_images' => [
                'Compress or resize the flagged images before serving them.',
                'Serve responsive image sizes and modern formats where possible.',
            ],
            'slow_response' => [
                'Profile backend, cache, and database work for this URL.',
                'Reduce time to first byte with page caching, query optimization, or infrastructure tuning.',
            ],
            'missing_alt_text' => [
                'Add useful alt text to meaningful images.',
                'Use empty alt text only for decorative images that should be ignored by assistive technology.',
            ],
            'missing_title' => [
                'Add a unique title tag to the page.',
                'Put the most specific page topic near the start of the title.',
            ],
            'title_too_short' => [
                'Rewrite the title with enough context to distinguish the page.',
                'Aim for a clear, specific title in the recommended 30-60 character range.',
            ],
            'title_too_long' => [
                'Shorten the title so the most important words are not truncated in search results.',
                'Aim for a clear, specific title in the recommended 30-60 character range.',
            ],
            'too_few_internal_links' => [
                'Add relevant internal links from this page to related pages.',
                'Use descriptive anchor text that helps users and crawlers understand the destination.',
            ],
            'too_many_internal_links' => [
                'Remove low-value navigation or repeated links from this page.',
                'Group links into clearer sections so crawlers and users can identify the most important destinations.',
            ],
            'duplicate_title' => [
                'Give each affected page a unique title that reflects its specific content.',
                'Check templates or CMS defaults that may be applying the same title broadly.',
            ],
            'duplicate_meta_description' => [
                'Write a unique meta description for each affected URL.',
                'If the pages are intentionally identical, consider canonicalizing or consolidating them.',
            ],
            'orphaned_page' => [
                'Add internal links from relevant pages or navigation to this URL.',
                'If the page should be discoverable but not linked, include it in the XML sitemap.',
            ],
            default => [
                'Review the flagged page and stored evidence to confirm the cause.',
                'Fix the page or template that generated the issue, then rerun the SEO check.',
            ],
        };

        if (filled($storedRecommendation)) {
            array_unshift($guidance, $this->formatDetailValue($storedRecommendation));
        }

        return collect($guidance)->unique()->values()->all();
    }

    protected function formatDetailValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }
}
