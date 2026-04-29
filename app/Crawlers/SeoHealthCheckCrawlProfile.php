<?php

namespace App\Crawlers;

use App\Models\SeoCheck;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlProfile;

class SeoHealthCheckCrawlProfile extends CrawlProfile
{
    protected UriInterface $baseUrl;

    public function __construct(
        protected SeoCheck $seoCheck,
        UriInterface|string $baseUrl,
    ) {
        $this->baseUrl = $baseUrl instanceof UriInterface ? $baseUrl : new Uri($baseUrl);
    }

    public function shouldCrawl(UriInterface $url): bool
    {
        if ($this->hasBeenCancelled()) {
            return false;
        }

        return $this->baseUrl->getHost() === $url->getHost();
    }

    protected function hasBeenCancelled(): bool
    {
        if ($this->seoCheck->status === SeoCheck::STATUS_CANCELLED) {
            return true;
        }

        $status = SeoCheck::query()
            ->whereKey($this->seoCheck->id)
            ->value('status');

        if ($status === SeoCheck::STATUS_CANCELLED) {
            $this->seoCheck->status = SeoCheck::STATUS_CANCELLED;

            return true;
        }

        return false;
    }
}
