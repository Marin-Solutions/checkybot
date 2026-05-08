<?php

namespace App\Crawlers;

use App\Models\OutboundLink;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use App\Support\ApiMonitorEvidenceRedactor;
use App\Support\UptimeTransportError;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class WebsiteOutboundLinkCrawler extends CrawlObserver
{
    private const OUTBOUND_LINK_WRITE_CHUNK_SIZE = 90;

    private const BROKEN_STATUS_CODE_MIN = 400;

    private const BROKEN_STATUS_CODE_MAX = 599;

    protected Website $website;

    protected array $crawledPages = [];

    protected bool $hasSuccessfulCrawl = false;

    protected bool $hasInternalCrawlFailure = false;

    protected array $newlyBrokenOutboundLinks = [];

    public function __construct(Website $website)
    {
        $this->website = $website;
    }

    /*
    * Called when the crawler will crawl the url.
    */
    public function willCrawl(UriInterface $url, ?string $linkText): void {}

    /*
     * Called when the crawler has crawled the given url successfully.
     */
    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $this->hasSuccessfulCrawl = true;

        $this->recordOutboundLink($url, $foundOnUrl, $response->getStatusCode());
    }

    /*
     * Called when the crawler had a problem crawling the given url.
     */
    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        if ($this->isWebsiteUrl($url)) {
            $this->hasInternalCrawlFailure = true;
        }

        $response = $requestException->getResponse();
        $transportError = $response ? null : UptimeTransportError::fromThrowable($requestException);

        $this->recordOutboundLink(
            $url,
            $foundOnUrl,
            $response?->getStatusCode(),
            $transportError,
        );

        Log::warning('Crawl failed for URL: '.$url, [
            'website_id' => $this->website->id,
            'error' => $requestException->getMessage(),
        ]);
    }

    /**
     * Called when the crawl has ended.
     */
    public function finishedCrawling(): void
    {
        $checkedAt = Carbon::now();

        DB::transaction(function () use ($checkedAt): void {
            $currentPages = $this->currentOutboundPages($checkedAt);

            $this->newlyBrokenOutboundLinks = $this->findNewlyBrokenOutboundLinks($currentPages);

            $this->refreshOutboundLinks($currentPages);

            $this->website->last_outbound_checked_at = $checkedAt;
            $this->website->save();
        });

        $this->sendErrorNotification();

        /* Create system log */
        Log::info('Outbound Links for website '.$this->website->url.' has been crawled.');
    }

    protected function currentOutboundPages(Carbon $checkedAt): Collection
    {
        return collect($this->crawledPages)
            ->map(fn (array $page): array => array_merge($page, [
                'last_checked_at' => $checkedAt,
            ]))
            ->keyBy(fn (array $page): string => implode("\0", [
                $page['website_id'],
                $page['found_on'],
                $page['outgoing_url'],
            ]));
    }

    protected function refreshOutboundLinks(Collection $currentPages): void
    {
        $canPruneStaleLinks = $this->hasSuccessfulCrawl && ! $this->hasInternalCrawlFailure;

        if ($currentPages->isEmpty() && ! $canPruneStaleLinks) {
            return;
        }

        if ($currentPages->isNotEmpty()) {
            $currentPages
                ->values()
                ->chunk(self::OUTBOUND_LINK_WRITE_CHUNK_SIZE)
                ->each(function ($pages): void {
                    OutboundLink::query()->upsert(
                        $pages->all(),
                        ['website_id', 'found_on', 'outgoing_url'],
                        [
                            'found_on',
                            'outgoing_url',
                            'http_status_code',
                            'transport_error_type',
                            'transport_error_message',
                            'transport_error_code',
                            'last_checked_at',
                        ],
                    );
                });
        }

        if (! $canPruneStaleLinks) {
            return;
        }

        if ($currentPages->isEmpty()) {
            OutboundLink::query()
                ->where('website_id', $this->website->id)
                ->delete();

            return;
        }

        OutboundLink::query()
            ->where('website_id', $this->website->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('found_on')
                    ->orWhereNull('outgoing_url');
            })
            ->delete();

        OutboundLink::query()
            ->where('website_id', $this->website->id)
            ->whereNotNull('found_on')
            ->whereNotNull('outgoing_url')
            ->select(['id', 'website_id', 'found_on', 'outgoing_url'])
            ->chunkById(self::OUTBOUND_LINK_WRITE_CHUNK_SIZE, function ($links) use ($currentPages): void {
                $staleLinkIds = $links
                    ->reject(fn (OutboundLink $link): bool => $currentPages->has(implode("\0", [
                        $link->website_id,
                        $link->found_on,
                        $link->outgoing_url,
                    ])))
                    ->modelKeys();

                if ($staleLinkIds === []) {
                    return;
                }

                OutboundLink::query()
                    ->whereKey($staleLinkIds)
                    ->delete();
            });
    }

    /**
     * @param  array{type: \App\Enums\UptimeTransportErrorType, message: string, code: int|null}|null  $transportError
     */
    protected function recordOutboundLink(
        UriInterface $url,
        ?UriInterface $foundOnUrl,
        ?int $statusCode,
        ?array $transportError = null,
    ): void {
        if (is_null($foundOnUrl)) {
            return;
        }

        $currentUrl = (string) $url;
        $foundOnUrl = (string) $foundOnUrl;
        $currentDomain = parse_url($currentUrl, PHP_URL_HOST);
        $foundOnUrlDomain = parse_url($foundOnUrl, PHP_URL_HOST);

        if (! $currentDomain || $currentDomain === $foundOnUrlDomain) {
            return;
        }

        $this->crawledPages[] = [
            'website_id' => $this->website->id,
            'outgoing_url' => $currentUrl,
            'found_on' => $foundOnUrl,
            'http_status_code' => $statusCode,
            'transport_error_type' => $transportError ? $transportError['type']->value : null,
            'transport_error_message' => $transportError
                ? ApiMonitorEvidenceRedactor::redactTransportErrorMessage($transportError['message'])
                : null,
            'transport_error_code' => $transportError['code'] ?? null,
        ];
    }

    protected function isWebsiteUrl(UriInterface $url): bool
    {
        $urlHost = parse_url((string) $url, PHP_URL_HOST);
        $websiteHost = parse_url($this->website->getBaseURL(), PHP_URL_HOST);

        return $urlHost !== null
            && $websiteHost !== null
            && strtolower($urlHost) === strtolower($websiteHost);
    }

    protected function sendErrorNotification(): void
    {
        if ($this->newlyBrokenOutboundLinks === []) {
            return;
        }

        app(HealthEventNotificationService::class)->notifyWebsite(
            $this->website,
            'outbound_link_broken',
            'danger',
            $this->brokenOutboundLinksSummary($this->newlyBrokenOutboundLinks),
        );
    }

    protected function findNewlyBrokenOutboundLinks(Collection $currentPages): array
    {
        $brokenPages = $currentPages
            ->filter(fn (array $page): bool => $this->isBrokenOutboundLink($page));

        if ($brokenPages->isEmpty()) {
            return [];
        }

        $existingBrokenKeys = OutboundLink::query()
            ->where('website_id', $this->website->id)
            ->where(function ($query): void {
                $query
                    ->whereBetween('http_status_code', [
                        self::BROKEN_STATUS_CODE_MIN,
                        self::BROKEN_STATUS_CODE_MAX,
                    ])
                    ->orWhereNotNull('transport_error_type');
            })
            ->get(['website_id', 'found_on', 'outgoing_url'])
            ->mapWithKeys(fn (OutboundLink $link): array => [
                $this->outboundLinkKey($link->website_id, $link->found_on, $link->outgoing_url) => true,
            ]);

        return $brokenPages
            ->reject(fn (array $page): bool => $existingBrokenKeys->has(
                $this->outboundLinkKey($page['website_id'], $page['found_on'], $page['outgoing_url'])
            ))
            ->values()
            ->all();
    }

    protected function isBrokenOutboundLink(array $page): bool
    {
        if (! empty($page['transport_error_type'])) {
            return true;
        }

        return is_int($page['http_status_code'])
            && $page['http_status_code'] >= self::BROKEN_STATUS_CODE_MIN
            && $page['http_status_code'] <= self::BROKEN_STATUS_CODE_MAX;
    }

    protected function outboundLinkKey(int $websiteId, ?string $foundOn, ?string $outgoingUrl): string
    {
        return implode("\0", [$websiteId, $foundOn, $outgoingUrl]);
    }

    protected function brokenOutboundLinksSummary(array $links): string
    {
        $count = count($links);
        $headline = $count === 1
            ? 'Outbound link check found 1 newly broken external link.'
            : "Outbound link check found {$count} newly broken external links.";

        $examples = collect($links)
            ->take(5)
            ->map(fn (array $link): string => $this->brokenOutboundLinkSummaryLine($link))
            ->implode("\n");

        if ($count > 5) {
            $examples .= "\n+".($count - 5).' more newly broken links.';
        }

        return $headline."\n\n".$examples;
    }

    protected function brokenOutboundLinkSummaryLine(array $link): string
    {
        if (! empty($link['transport_error_type'])) {
            $reason = str_replace('_', ' ', $link['transport_error_type']);

            return "{$link['outgoing_url']} could not be reached ({$reason}) from {$link['found_on']}";
        }

        return "{$link['outgoing_url']} returned HTTP {$link['http_status_code']} from {$link['found_on']}";
    }
}
