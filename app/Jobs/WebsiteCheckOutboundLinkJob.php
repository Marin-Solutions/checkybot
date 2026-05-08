<?php

namespace App\Jobs;

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Models\OutboundLink;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use App\Support\ApiMonitorEvidenceRedactor;
use App\Support\UptimeTransportError;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;

class WebsiteCheckOutboundLinkJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const BROKEN_STATUS_CODE_MIN = 400;

    private const BROKEN_STATUS_CODE_MAX = 599;

    public const SOURCE_SCHEDULED = 'scheduled';

    public const SOURCE_ON_DEMAND = 'on-demand';

    public function __construct(
        public Website $website,
        public string $source = self::SOURCE_SCHEDULED,
    ) {
        //
    }

    public static function scheduled(Website $website): self
    {
        return new self($website, self::SOURCE_SCHEDULED);
    }

    public static function onDemand(Website $website): self
    {
        return new self($website, self::SOURCE_ON_DEMAND);
    }

    public function uniqueId(): string
    {
        return "website-outbound-link:{$this->source}:{$this->website->getKey()}";
    }

    public function uniqueFor(): int
    {
        return match ($this->source) {
            self::SOURCE_ON_DEMAND => 300,
            default => 86400,
        };
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->createCrawler()
                ->setCrawlObserver(new WebsiteOutboundLinkCrawler($this->website))
                ->startCrawling($this->website->getBaseURL());
        } catch (\Throwable $e) {
            Log::error('Outbound link check failed for website '.$this->website->url.': '.$e->getMessage());

            try {
                $this->recordStartupFailure($e);
            } catch (\Throwable $recordingException) {
                Log::error('Failed to record outbound link startup failure evidence for website '.$this->website->url.': '.$recordingException->getMessage());
                $this->clearQueuedOutboundScan();
            }
        }
    }

    public function createCrawler(): Crawler
    {
        return Crawler::create();
    }

    private function recordStartupFailure(\Throwable $exception): void
    {
        $checkedAt = Carbon::now();
        $baseUrl = $this->failureEvidenceUrl();
        $transportError = UptimeTransportError::fromThrowable($exception);
        $scanSource = $this->sourceLabel();
        $wasAlreadyBroken = $this->hasExistingBrokenEvidence($baseUrl);

        OutboundLink::query()->upsert(
            [[
                'website_id' => $this->website->id,
                'found_on' => $baseUrl,
                'outgoing_url' => $baseUrl,
                'http_status_code' => null,
                'transport_error_type' => $transportError['type']->value,
                'transport_error_message' => ApiMonitorEvidenceRedactor::redactTransportErrorMessage(
                    "Outbound {$scanSource} scan failed before crawling started: {$transportError['message']}"
                ),
                'transport_error_code' => $transportError['code'],
                'last_checked_at' => $checkedAt,
            ]],
            ['website_id', 'found_on', 'outgoing_url'],
            [
                'http_status_code',
                'transport_error_type',
                'transport_error_message',
                'transport_error_code',
                'last_checked_at',
            ],
        );

        $this->website->forceFill([
            'last_outbound_checked_at' => $checkedAt,
            'outbound_scan_queued_at' => null,
        ])->save();

        if (! $wasAlreadyBroken) {
            app(HealthEventNotificationService::class)->notifyWebsite(
                $this->website,
                'outbound_link_broken',
                'danger',
                $this->startupFailureSummary($baseUrl, $transportError['type']->value),
            );
        }
    }

    private function hasExistingBrokenEvidence(string $url): bool
    {
        return OutboundLink::query()
            ->where('website_id', $this->website->id)
            ->where('found_on', $url)
            ->where('outgoing_url', $url)
            ->where(function ($query): void {
                $query
                    ->whereBetween('http_status_code', [
                        self::BROKEN_STATUS_CODE_MIN,
                        self::BROKEN_STATUS_CODE_MAX,
                    ])
                    ->orWhereNotNull('transport_error_type');
            })
            ->exists();
    }

    private function startupFailureSummary(string $url, string $transportErrorType): string
    {
        $reason = UptimeTransportError::label($transportErrorType);

        return "Outbound link check found 1 newly broken external link.\n\n{$url} could not be reached ({$reason}) from {$url}";
    }

    private function clearQueuedOutboundScan(): void
    {
        try {
            $this->website->forceFill([
                'outbound_scan_queued_at' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Failed to clear queued outbound scan state for website '.$this->website->url.': '.$e->getMessage());
        }
    }

    private function failureEvidenceUrl(): string
    {
        try {
            return $this->website->getBaseURL();
        } catch (\Throwable) {
            return $this->website->url ?: "website:{$this->website->getKey()}";
        }
    }

    private function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_SCHEDULED => 'scheduled',
            self::SOURCE_ON_DEMAND => 'on demand',
            default => 'unknown source',
        };
    }
}
