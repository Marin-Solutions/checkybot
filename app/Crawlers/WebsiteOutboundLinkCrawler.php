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
        if (! is_null($foundOnUrl)) {
            $currentUrl = (string) $url;
            $foundOnUrl = (string) $foundOnUrl;
            $currentDomain = parse_url($currentUrl, PHP_URL_HOST);
            $foundOnUrlDomain = parse_url($foundOnUrl, PHP_URL_HOST);

            if ($currentDomain && $currentDomain !== $foundOnUrlDomain) {
                $this->crawledPages[] = [
                    'website_id' => $this->website->id,
                    'outgoing_url' => $currentUrl,
                    'found_on' => $foundOnUrl,
                    'http_status_code' => $response->getStatusCode(),
                    'transport_error_type' => null,
                    'transport_error_message' => null,
                    'transport_error_code' => null,
                    'last_checked_at' => Carbon::now(),
                ];
            }
        }
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
        if (! is_null($foundOnUrl)) {
            $currentUrl = (string) $url;
            $foundOnUrl = (string) $foundOnUrl;
            $currentDomain = parse_url($currentUrl, PHP_URL_HOST);
            $foundOnUrlDomain = parse_url($foundOnUrl, PHP_URL_HOST);

            if ($currentDomain && $currentDomain !== $foundOnUrlDomain) {
                $transportError = UptimeTransportError::fromThrowable($requestException);

                $this->crawledPages[] = [
                    'website_id' => $this->website->id,
                    'outgoing_url' => $currentUrl,
                    'found_on' => $foundOnUrl,
                    'http_status_code' => null,
                    'transport_error_type' => $transportError['type']->value,
                    'transport_error_message' => ApiMonitorEvidenceRedactor::redactTransportErrorMessage($transportError['message']),
                    'transport_error_code' => $transportError['code'],
                    'last_checked_at' => Carbon::now(),
                ];
            }
        }

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
            ->keyBy(fn (array $page): string => $this->linkKey($page['found_on'], $page['outgoing_url']));

        OutboundLink::query()
            ->where('website_id', $this->website->id)
            ->get()
            ->groupBy(fn (OutboundLink $link): string => $this->linkKey($link->found_on, $link->outgoing_url))
            ->each(function ($links, string $key) use ($currentPages): void {
                if (! $currentPages->has($key)) {
                    $links->each->delete();

                    return;
                }

                $link = $links->first();

                $link->fill($currentPages->get($key));
                $link->save();

                $links->slice(1)->each->delete();
                $currentPages->forget($key);
            });

        $currentPages->each(fn (array $page): OutboundLink => OutboundLink::query()->create($page));
    }

    protected function linkKey(?string $foundOn, ?string $outgoingUrl): string
    {
        return hash('sha256', serialize([$this->website->id, $foundOn, $outgoingUrl]));
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
