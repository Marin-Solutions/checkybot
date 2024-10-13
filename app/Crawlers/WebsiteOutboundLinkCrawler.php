<?php

    namespace App\Crawlers;

    use App\Mail\EmailErrorOutgoingUrl;
    use App\Mail\EmailReminderSsl;
    use App\Models\OutboundLink;
    use App\Models\User;
    use App\Models\Website;
    use DOMDocument;
    use GuzzleHttp\Exception\RequestException;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Mail;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\UriInterface;
    use Spatie\Crawler\CrawlObservers\CrawlObserver;

    class WebsiteOutboundLinkCrawler extends CrawlObserver
    {
        protected Website $website;
        protected array $crawledPages = [];

        public function __construct( Website $website )
        {
            $this->website = $website;
        }

        /*
        * Called when the crawler will crawl the url.
        */
        public function willCrawl( UriInterface $url, ?string $linkText ): void
        {
        }

        /*
         * Called when the crawler has crawled the given url successfully.
         */
        public function crawled(
            UriInterface      $url,
            ResponseInterface $response,
            ?UriInterface     $foundOnUrl = null,
            ?string           $linkText = null
        ): void
        {
            if ( !is_null($foundOnUrl) ) {
                $currentUrl       = (string) $url;
                $foundOnUrl       = (string) $foundOnUrl;
                $currentDomain    = parse_url($currentUrl, PHP_URL_HOST);
                $foundOnUrlDomain = parse_url($foundOnUrl, PHP_URL_HOST);

                if ( $currentDomain && $currentDomain !== $foundOnUrlDomain ) {
                    $this->crawledPages[] = [
                        'website_id'       => $this->website->id,
                        'outgoing_url'     => $currentUrl,
                        'found_on'         => $foundOnUrl,
                        'http_status_code' => $response->getStatusCode(),
                        'last_checked_at'  => Carbon::now(),
                    ];
                }
            }
        }

        /*
         * Called when the crawler had a problem crawling the given url.
         */
        public function crawlFailed(
            UriInterface     $url,
            RequestException $requestException,
            ?UriInterface    $foundOnUrl = null,
            ?string          $linkText = null,
        ): void
        {
            // TODO: Implement crawlFailed() method.
        }

        /**
         * Called when the crawl has ended.
         */
        public function finishedCrawling(): void
        {
            OutboundLink::query()->insert($this->crawledPages);

            $this->website->last_outbound_checked_at = Carbon::now();
            $this->website->save();

            $this->sendErrorNotification();

            /*Create system log*/
            Log::info('Outbound Links for website ' . $this->website[ 'url' ] . ' has been crawled.');
        }

        protected function sendErrorNotification(): void
        {
            $user = $this->website->user;

            foreach ( $this->crawledPages as $page ) {
                if ( $page[ 'http_status_code' ] === 404 || $page[ 'http_status_code' ] === 500 ) {
                    Mail::to($user)->send(new EmailErrorOutgoingUrl(array_merge([ 'user' => $user ], $page)));
                }
            }
        }
    }
