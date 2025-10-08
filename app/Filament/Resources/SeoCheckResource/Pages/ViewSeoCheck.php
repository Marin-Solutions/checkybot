<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use App\Models\SeoCheck;
use App\Services\SeoReportGenerationService;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

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
            Actions\ActionGroup::make([
                Actions\Action::make('export_pdf')
                    ->label('Export PDF Report')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->url(fn() => $this->getExportUrl('html'))
                    ->openUrlInNewTab()
                    ->visible(fn() => $this->getRecord()->isCompleted()),
                Actions\Action::make('export_csv')
                    ->label('Export CSV Data')
                    ->icon('heroicon-o-table-cells')
                    ->color('info')
                    ->url(fn() => $this->getExportUrl('csv'))
                    ->openUrlInNewTab()
                    ->visible(fn() => $this->getRecord()->isCompleted()),
            ])
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->visible(fn() => $this->getRecord()->isCompleted()),
        ];
    }

    #[On('refresh-seo-check-data')]
    public function refreshSeoCheckData(): void
    {
        $this->refreshFormData(['record']);
    }

    #[On('seo-check-finished')]
    public function handleSeoCheckFinished(): void
    {
        // Refresh all data when SEO check completes
        $this->refreshFormData(['record']);

        // Don't send notification here to avoid duplicates
        // The Livewire component will handle the notification
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Live Progress')
                    ->schema([
                        \Filament\Infolists\Components\Livewire::make(\App\Livewire\SeoCheckProgress::class, [
                            'seoCheck' => $this->getRecord(),
                        ]),
                    ])
                    ->visible(fn() => $this->getRecord()->isRunning()),
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
                                TextEntry::make('total_urls_crawled')
                                    ->label('URLs Crawled'),
                                TextEntry::make('started_at')
                                    ->label('Started At')
                                    ->dateTime(),
                                TextEntry::make('finished_at')
                                    ->label('Finished At')
                                    ->dateTime(),
                                TextEntry::make('started_at')
                                    ->label('Duration')
                                    ->formatStateUsing(function ($record) {
                                        if ($record->started_at && $record->finished_at) {
                                            return $record->started_at->diffForHumans($record->finished_at, true);
                                        }

                                        return 'N/A';
                                    }),
                            ]),
                    ]),
                Section::make('SEO Summary')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('health_score_formatted')
                                    ->label('Health Score')
                                    ->badge()
                                    ->color(fn($record): string => $record->health_score_color)
                                    ->formatStateUsing(fn($record): string => $record->health_score_formatted),
                                TextEntry::make('errors_count')
                                    ->label('Errors')
                                    ->badge()
                                    ->color('danger'),
                                TextEntry::make('warnings_count')
                                    ->label('Warnings')
                                    ->badge()
                                    ->color('warning'),
                                TextEntry::make('notices_count')
                                    ->label('Notices')
                                    ->badge()
                                    ->color('info'),
                            ]),
                    ])
                    ->visible(fn($record): bool => $record->isCompleted() || $record->isFailed()),
            ]);
    }

    protected function getFooterWidgets(): array
    {
        $record = $this->getRecord();
        $widgets = [];

        // Add trend widget if there are previous checks
        $previousChecks = SeoCheck::where('website_id', $record->website_id)
            ->where('status', 'completed')
            ->where('id', '!=', $record->id)
            ->count();

        if ($previousChecks > 0) {
            $widgets[] = \App\Filament\Widgets\SeoHealthScoreTrendWidget::make();
        }

        // Add issues table if check is completed or has issues
        if ($record->isCompleted() || $record->seoIssues()->exists()) {
            $widgets[] = \App\Filament\Widgets\SeoIssuesTableWidget::make([
                'recordId' => $record->id,
            ]);
        }

        return $widgets;
    }

    public function getExportUrl(string $format): string
    {
        $seoCheck = $this->getRecord();
        $reportService = app(SeoReportGenerationService::class);

        $filename = $reportService->generateComprehensiveReport($seoCheck, $format);

        return $reportService->getReportDownloadUrl($filename);
    }

    public function exportToPdf()
    {
        return redirect($this->getExportUrl('html'));
    }

    public function exportToCsv()
    {
        return redirect($this->getExportUrl('csv'));
    }
}
