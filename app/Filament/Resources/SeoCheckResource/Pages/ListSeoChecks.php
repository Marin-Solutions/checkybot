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
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        // Filter by website if website_id is provided in the URL
        if (request()->has('website_id')) {
            $websiteId = request()->get('website_id');
            $this->website = Website::find($websiteId);
            $query->where('website_id', $websiteId);
        }

        return $query;
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
