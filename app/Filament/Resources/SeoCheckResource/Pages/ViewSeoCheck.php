<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use App\Models\SeoCheck;
use App\Services\SeoReportGenerationService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Livewire\Attributes\On;

class ViewSeoCheck extends ViewRecord
{
    protected static string $resource = SeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel')
                ->label('Cancel Check')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancel SEO Check')
                ->modalDescription('Are you sure you want to cancel this SEO check? This action cannot be undone.')
                ->action(function () {
                    $record = $this->getRecord();
                    $cancelled = SeoCheck::query()
                        ->whereKey($record->id)
                        ->whereIn('status', [SeoCheck::STATUS_PENDING, SeoCheck::STATUS_RUNNING])
                        ->update([
                            'status' => SeoCheck::STATUS_CANCELLED,
                            'finished_at' => now(),
                        ]);

                    if ($cancelled === 0) {
                        \Filament\Notifications\Notification::make()
                            ->title('SEO Check Already Finished')
                            ->body('This SEO check can no longer be cancelled.')
                            ->warning()
                            ->send();

                        $this->refreshFormData(['record']);

                        return;
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('SEO Check Cancelled')
                        ->success()
                        ->send();

                    $this->refreshFormData(['record']);
                })
                ->visible(fn () => $this->getRecord()->isCancellable()),
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
                    ->url(fn () => $this->getExportUrl('html'))
                    ->openUrlInNewTab()
                    ->visible(fn () => $this->getRecord()->isCompleted()),
                Actions\Action::make('export_csv')
                    ->label('Export CSV Data')
                    ->icon('heroicon-o-table-cells')
                    ->color('info')
                    ->url(fn () => $this->getExportUrl('csv'))
                    ->openUrlInNewTab()
                    ->visible(fn () => $this->getRecord()->isCompleted()),
            ])
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->visible(fn () => $this->getRecord()->isCompleted()),
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

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Live Progress')
                    ->schema([
                        \Filament\Schemas\Components\Livewire::make(\App\Livewire\SeoCheckProgress::class, [
                            'seoCheck' => $this->getRecord(),
                        ])
                            ->key('seo-check-progress-'.$this->getRecord()->id),
                    ])
                    ->visible(fn () => $this->getRecord()->isRunning())
                    ->columnSpanFull(),
                \Filament\Schemas\Components\Section::make('SEO Check Overview')
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('website.name')
                                    ->label('Website'),
                                \Filament\Infolists\Components\TextEntry::make('website.url')
                                    ->label('URL')
                                    ->url(fn ($record) => $record->website->url)
                                    ->openUrlInNewTab(),
                                \Filament\Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'completed' => 'success',
                                        'running' => 'warning',
                                        'failed' => 'danger',
                                        'cancelled' => 'gray',
                                        'pending' => 'gray',
                                    }),
                                \Filament\Infolists\Components\TextEntry::make('total_urls_crawled')
                                    ->label('URLs Crawled'),
                                \Filament\Infolists\Components\TextEntry::make('started_at')
                                    ->label('Started At')
                                    ->dateTimeInUserZone(),
                                \Filament\Infolists\Components\TextEntry::make('finished_at')
                                    ->label('Finished At')
                                    ->dateTimeInUserZone(),
                                \Filament\Infolists\Components\TextEntry::make('started_at')
                                    ->label('Duration')
                                    ->formatStateUsing(function ($record) {
                                        if ($record->started_at && $record->finished_at) {
                                            return $record->started_at->diffForHumans($record->finished_at, true);
                                        }

                                        return 'N/A';
                                    }),
                            ]),
                    ]),
                \Filament\Schemas\Components\Section::make('Failure Details')
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('failure_summary')
                            ->label('What failed')
                            ->copyable()
                            ->columnSpanFull(),
                        \Filament\Infolists\Components\KeyValueEntry::make('failure_context')
                            ->label('Context')
                            ->state(fn ($record): array => $this->formatFailureContext($record->failure_context ?? []))
                            ->hidden(fn ($record): bool => blank($record->failure_context)),
                    ])
                    ->visible(fn ($record): bool => $record->isFailed() && filled($record->failure_summary)),
                \Filament\Schemas\Components\Section::make('SEO Summary')
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(4)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('health_score_formatted')
                                    ->label('Health Score')
                                    ->badge()
                                    ->color(fn ($record): string => $record->health_score_color)
                                    ->formatStateUsing(fn ($record): string => $record->health_score_formatted),
                                \Filament\Infolists\Components\TextEntry::make('errors_count')
                                    ->label('Errors')
                                    ->badge()
                                    ->color('danger'),
                                \Filament\Infolists\Components\TextEntry::make('warnings_count')
                                    ->label('Warnings')
                                    ->badge()
                                    ->color('warning'),
                                \Filament\Infolists\Components\TextEntry::make('notices_count')
                                    ->label('Notices')
                                    ->badge()
                                    ->color('info'),
                            ]),
                    ])
                    ->visible(fn ($record): bool => $record->isCompleted() || $record->isFailed()),
            ]);
    }

    protected function formatFailureContext(array $context): array
    {
        return collect($context)
            ->map(function ($value): string {
                if (is_array($value)) {
                    return implode(', ', array_map(
                        fn ($nestedValue): string => is_scalar($nestedValue) || $nestedValue === null
                            ? (string) $nestedValue
                            : json_encode($nestedValue, JSON_UNESCAPED_SLASHES),
                        $value
                    ));
                }

                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                return (string) $value;
            })
            ->all();
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
            $widgets[] = \App\Filament\Widgets\SeoHealthScoreTrendWidget::make([
                'recordId' => $record->id,
                'websiteId' => $record->website_id,
            ]);
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
