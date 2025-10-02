<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ViewRecord;

class ViewSeoCheck extends ViewRecord
{
    protected static string $resource = SeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Data')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $this->refreshFormData(['record']);
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('SEO Check Overview')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('website.name')
                                    ->label('Website'),
                                TextEntry::make('website.url')
                                    ->label('URL')
                                    ->url(fn($record) => $record->website->url)
                                    ->openUrlInNewTab(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn(string $state): string => match ($state) {
                                        'completed' => 'success',
                                        'running' => 'warning',
                                        'failed' => 'danger',
                                        'pending' => 'gray',
                                    }),
                                TextEntry::make('health_score')
                                    ->label('Health Score')
                                    ->formatStateUsing(fn($state) => $state ? $state . '%' : 'N/A')
                                    ->badge()
                                    ->color(function ($state) {
                                        if (! $state) {
                                            return 'gray';
                                        }
                                        if ($state >= 80) {
                                            return 'success';
                                        }
                                        if ($state >= 60) {
                                            return 'warning';
                                        }

                                        return 'danger';
                                    }),
                                TextEntry::make('total_urls_crawled')
                                    ->label('URLs Crawled'),
                                TextEntry::make('started_at')
                                    ->label('Started At')
                                    ->dateTime(),
                                TextEntry::make('finished_at')
                                    ->label('Finished At')
                                    ->dateTime(),
                                TextEntry::make('duration')
                                    ->label('Duration')
                                    ->formatStateUsing(function ($record) {
                                        if ($record->started_at && $record->finished_at) {
                                            return $record->started_at->diffForHumans($record->finished_at, true);
                                        }

                                        return 'N/A';
                                    }),
                            ]),
                    ]),
                Section::make('Issues Summary')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('errors_found')
                                    ->label('Errors')
                                    ->badge()
                                    ->color('danger'),
                                TextEntry::make('warnings_found')
                                    ->label('Warnings')
                                    ->badge()
                                    ->color('warning'),
                                TextEntry::make('notices_found')
                                    ->label('Notices')
                                    ->badge()
                                    ->color('info'),
                            ]),
                    ]),
            ]);
    }

    public function getTabs(): array
    {
        return [
            'overview' => Tab::make('Overview')
                ->icon('heroicon-o-information-circle'),
            'crawl_results' => Tab::make('Crawl Results')
                ->icon('heroicon-o-table-cells')
                ->content(function () {
                    $seoCheck = $this->record;
                    $results = $seoCheck->crawlResults()->paginate(50);

                    return view('filament.pages.seo-check-results', [
                        'results' => $results,
                        'seoCheck' => $seoCheck,
                    ]);
                }),
        ];
    }
}
