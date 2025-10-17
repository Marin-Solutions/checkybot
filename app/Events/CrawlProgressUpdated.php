<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CrawlProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;



    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $seoCheckId,
        public int $urlsCrawled,
        public int $totalUrls,
        public int $issuesFound,
        public int $progress,
        public ?string $currentUrl = null,
        public ?int $etaSeconds = null
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new \Illuminate\Broadcasting\Channel('seo-checks.' . $this->seoCheckId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'crawl-progress-updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'seoCheckId' => $this->seoCheckId,
            'urlsCrawled' => $this->urlsCrawled,
            'totalUrls' => $this->totalUrls,
            'issuesFound' => $this->issuesFound,
            'progress' => $this->progress,
            'currentUrl' => $this->currentUrl,
            'etaSeconds' => $this->etaSeconds,
        ];
    }
}
