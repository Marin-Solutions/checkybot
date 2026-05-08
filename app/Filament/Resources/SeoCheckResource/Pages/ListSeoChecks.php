<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use App\Models\Website;
use App\Services\SeoHealthCheckService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListSeoChecks extends ListRecords
{
    protected static string $resource = SeoCheckResource::class;

    #[Url(as: 'website_id')]
    public ?int $websiteId = null;

    protected ?Website $website = null;

    public function mount(): void
    {
        parent::mount();

        $this->websiteId ??= request()->integer('website_id') ?: null;
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        $website = $this->resolveWebsite();

        // Add "Back to All Websites" action when filtering by website
        if ($website) {
            $actions[] = Actions\Action::make('back_to_all')
                ->label('Back to All Websites')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(route('filament.admin.resources.website-seo-checks.index'));

            $actions[] = Actions\Action::make('run_seo_check')
                ->label('Run SEO Check')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Start SEO Health Check')
                ->modalDescription('This will start a comprehensive SEO health check for this website. The process may take several minutes depending on the site size.')
                ->action(function (SeoHealthCheckService $seoService, Actions\Action $action) use ($website) {
                    try {
                        $seoCheck = $seoService->startManualCheck($website);

                        Notification::make()
                            ->title('SEO Check Started')
                            ->body("SEO health check has been started for {$website->name}. You can monitor the progress in real-time.")
                            ->success()
                            ->send();

                        $action->successRedirectUrl(SeoCheckResource::getUrl('view', [
                            'record' => $seoCheck,
                        ]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error Starting SEO Check')
                            ->body('Failed to start SEO check: '.$e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(function () use ($website) {
                    $latestCheck = $website->latestSeoCheck;

                    return ! $latestCheck || ! in_array($latestCheck->status, ['running', 'pending']);
                });
        }

        return $actions;
    }

    protected function getTableQuery(): Builder
    {
        // The filtering is now handled in the resource's getEloquentQuery method
        $query = parent::getTableQuery();

        // Set the website for title and breadcrumbs
        if ($this->websiteId || request()->has('website_id')) {
            $websiteId = $this->websiteId ?? request()->get('website_id');
            $this->website = Website::query()
                ->where('created_by', auth()->id())
                ->find($websiteId);
        }

        return $query;
    }

    protected function getDefaultTableSortColumn(): ?string
    {
        return 'finished_at';
    }

    protected function getDefaultTableSortDirection(): ?string
    {
        return 'desc';
    }

    public function getTitle(): string
    {
        if ($this->website) {
            return "SEO Checks for {$this->website->name}";
        }

        return 'All SEO Checks';
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = parent::getBreadcrumbs();

        if ($this->website) {
            // Add breadcrumb to go back to website SEO checks
            $breadcrumbs = [
                'SEO Checks' => route('filament.admin.resources.website-seo-checks.index'),
                "SEO Checks for {$this->website->name}" => null,
            ];
        }

        return $breadcrumbs;
    }

    protected function resolveWebsite(): ?Website
    {
        if ($this->website) {
            return $this->website;
        }

        $websiteId = $this->websiteId ?: (request()->integer('website_id') ?: null);

        if (! $websiteId) {
            return null;
        }

        $this->website = Website::query()
            ->where('created_by', auth()->id())
            ->find($websiteId);

        return $this->website;
    }
}
