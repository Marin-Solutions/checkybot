<?php

namespace App\Crawlers;

use App\Mail\EmailErrorOutgoingUrl;
use App\Models\OutboundLink;
use App\Models\Website;
use App\Support\ApiMonitorEvidenceRedactor;
use App\Support\UptimeTransportError;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class WebsiteOutboundLinkCrawler extends CrawlObserver
{
    protected Website $website;

    protected array $crawledPages = [];

    protected bool $hasSuccessfulCrawl = false;

    protected bool $hasInternalCrawlFailure = false;

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
            $this->refreshOutboundLinks($checkedAt);

            $this->website->last_outbound_checked_at = $checkedAt;
            $this->website->save();
        });

        $this->sendErrorNotification();

        /* Create system log */
        Log::info('Outbound Links for website '.$this->website->url.' has been crawled.');
    }

    protected function refreshOutboundLinks(Carbon $checkedAt): void
    {
        $currentPages = collect($this->crawledPages)
            ->map(fn (array $page): array => array_merge($page, [
                'last_checked_at' => $checkedAt,
            ]))
            ->keyBy(fn (array $page): string => hash('sha256', json_encode([
                $page['website_id'],
                $page['found_on'],
                $page['outgoing_url'],
            ])));

        $canPruneStaleLinks = $this->hasSuccessfulCrawl && ! $this->hasInternalCrawlFailure;

        if ($currentPages->isEmpty() && ! $canPruneStaleLinks) {
            return;
        }

        if ($currentPages->isNotEmpty()) {
            OutboundLink::query()->upsert(
                $currentPages->values()->all(),
                ['website_id', 'found_on', 'outgoing_url'],
                [
                    'http_status_code',
                    'transport_error_type',
                    'transport_error_message',
                    'transport_error_code',
                    'last_checked_at',
                ],
            );
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
            ->whereNot(function ($query) use ($currentPages): void {
                $currentPages->each(function (array $page) use ($query): void {
                    $query->orWhere(function ($query) use ($page): void {
                        $query
                            ->where('found_on', $page['found_on'])
                            ->where('outgoing_url', $page['outgoing_url']);
                    });
                });
            })
            ->delete();
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
        $user = $this->website->user;

        if (! $user) {
            return;
        }

        foreach ($this->crawledPages as $page) {
            if ($page['http_status_code'] === 404 || $page['http_status_code'] === 500) {
                Mail::to($user)->send(new EmailErrorOutgoingUrl(array_merge(['user' => $user], $page)));
            }
        }
    }
}
