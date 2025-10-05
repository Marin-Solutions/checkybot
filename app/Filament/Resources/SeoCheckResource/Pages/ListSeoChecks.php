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
            $query->where('website_id', $websiteId);
        }

        return $query;
    }

    public function getTitle(): string
    {
        if (request()->has('website_id')) {
            $website = Website::find(request()->get('website_id'));
            if ($website) {
                return "SEO Checks for {$website->name}";
            }
        }

        return 'All SEO Checks';
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = parent::getBreadcrumbs();

        if (request()->has('website_id')) {
            $website = Website::find(request()->get('website_id'));
            if ($website) {
                // Add breadcrumb to go back to website SEO checks
                $breadcrumbs = [
                    'SEO Checks' => route('filament.admin.resources.website-seo-checks.index'),
                    "SEO Checks for {$website->name}" => null,
                ];
            }
        }

        return $breadcrumbs;
    }
}
