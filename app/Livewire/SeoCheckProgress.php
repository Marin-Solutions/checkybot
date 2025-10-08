<?php

namespace App\Livewire;

use App\Models\SeoCheck;
use Livewire\Attributes\On;
use Livewire\Component;

class SeoCheckProgress extends Component
{
    public SeoCheck $seoCheck;

    public bool $isRunning = false;

    public int $progress = 0;

    public int $urlsCrawled = 0;

    public int $totalUrls = 0;

    public int $issuesFound = 0;

    public ?string $estimatedTime = null;

    public ?string $currentUrl = null;

    public ?\Carbon\Carbon $startedAt = null;

    public function mount(SeoCheck $seoCheck): void
    {
        $this->seoCheck = $seoCheck;
        $this->initializeProgress();
    }

    protected function initializeProgress(): void
    {
        $this->seoCheck->refresh();

        $this->isRunning = $this->seoCheck->isRunning();
        $this->urlsCrawled = $this->seoCheck->total_urls_crawled ?? 0;
        $this->totalUrls = $this->seoCheck->total_crawlable_urls ?? 1;
        $this->progress = $this->seoCheck->getProgressPercentage();
        $this->issuesFound = $this->seoCheck->seoIssues()->count();
        $this->startedAt = $this->seoCheck->started_at;

        $this->calculateEstimatedTime();
    }

    #[On('seo-check-progress-updated')]
    public function updateProgress(): void
    {
        // Refresh from database to get latest progress data
        $this->seoCheck->refresh();

        // Update component properties
        $this->isRunning = $this->seoCheck->isRunning();
        $this->urlsCrawled = $this->seoCheck->total_urls_crawled ?? 0;
        $this->totalUrls = $this->seoCheck->total_crawlable_urls ?? 1;
        $this->progress = $this->seoCheck->getProgressPercentage();
        $this->issuesFound = $this->seoCheck->seoIssues()->count();

        // Recalculate estimated time
        $this->calculateEstimatedTime();

        // Dispatch event to update the parent page sections
        $this->dispatch('refresh-seo-check-data');
    }

    #[On('seo-check-completed')]
    public function handleCompletion(): void
    {
        // Refresh from database only on completion to get final data
        $this->seoCheck->refresh();

        $this->isRunning = $this->seoCheck->isRunning();
        $this->urlsCrawled = $this->seoCheck->total_urls_crawled;
        $this->totalUrls = $this->seoCheck->total_crawlable_urls;
        $this->progress = $this->seoCheck->getProgressPercentage();
        $this->issuesFound = $this->seoCheck->seoIssues()->count();
        $this->startedAt = $this->seoCheck->started_at;

        // Dispatch completion event to update all sections
        $this->dispatch('seo-check-finished');
    }

    protected function calculateEstimatedTime(): void
    {
        if (! $this->isRunning || $this->urlsCrawled === 0 || ! $this->startedAt) {
            $this->estimatedTime = null;

            return;
        }

        $elapsedSeconds = $this->startedAt->diffInSeconds(now());
        $avgTimePerUrl = $elapsedSeconds / $this->urlsCrawled;
        $remainingUrls = $this->totalUrls - $this->urlsCrawled;
        $estimatedSeconds = $remainingUrls * $avgTimePerUrl;

        if ($estimatedSeconds > 0) {
            $this->estimatedTime = now()->addSeconds($estimatedSeconds)->diffForHumans();
        } else {
            $this->estimatedTime = 'Almost done';
        }
    }

    public function render()
    {
        return view('livewire.seo-check-progress');
    }
}
