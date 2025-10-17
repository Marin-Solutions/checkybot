<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use App\Models\Website;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSeoChecks extends ListRecords
{
    protected static string $resource = SeoCheckResource::class;

    protected ?Website $website = null;

    protected function getHeaderActions(): array
    {
        $actions = [];

        // Add "Back to All Websites" action when filtering by website
        if ($this->website) {
            $actions[] = Actions\Action::make('back_to_all')
                ->label('Back to All Websites')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(route('filament.admin.resources.website-seo-checks.index'));
        }

        $actions[] = Actions\CreateAction::make();

        return $actions;
    }

    protected function getTableQuery(): Builder
    {
        // The filtering is now handled in the resource's getEloquentQuery method
        $query = parent::getTableQuery();

        // Set the website for title and breadcrumbs
        if (request()->has('website_id')) {
            $websiteId = request()->get('website_id');
            $this->website = Website::find($websiteId);
        }

        return $query;
    }

    protected function getDefaultTableSortColumn(): ?string
    {
        return 'finished_at';
    }

    protected function getDefaultTableSortDirection(): ?string
    {
        return 'asc';
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
}
