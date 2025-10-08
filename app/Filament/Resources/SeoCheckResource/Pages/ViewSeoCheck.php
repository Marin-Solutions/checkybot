<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ViewSeoCheck extends ViewRecord implements HasTable
{
    use InteractsWithTable;

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

        // Show success notification
        \Filament\Notifications\Notification::make()
            ->title('SEO Check Completed')
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn() => $this->getRecord()->seoIssues()->with('seoCrawlResult'))
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn($state): string => match ($state->value) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        'notice' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state): string => strtoupper($state->value))
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Issue')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label('URL')
                    ->url(fn($state) => $state)
                    ->openUrlInNewTab()
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('description')
                    ->limit(100)
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'error' => 'Error',
                        'warning' => 'Warning',
                        'notice' => 'Notice',
                    ])
                    ->placeholder('All severities'),
                Filter::make('title')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('title')
                            ->label('Filter by Issue Title')
                            ->placeholder('Search issue titles...'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['title'],
                                fn(Builder $query, $title): Builder => $query->where('title', 'like', "%{$title}%"),
                            );
                    }),
                Filter::make('description')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('description')
                            ->label('Filter by Description')
                            ->placeholder('Search descriptions...'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['description'],
                                fn(Builder $query, $description): Builder => $query->where('description', 'like', "%{$description}%"),
                            );
                    }),
            ])
            ->defaultSort('severity')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
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
                    ->visible(fn() => $this->getRecord()->isRunning() || $this->getRecord()->isCompleted() || $this->getRecord()->isFailed()),
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
                Section::make('Health Score')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('health_score_formatted')
                                    ->label('Health Score')
                                    ->badge()
                                    ->color(fn($record): string => $record->health_score_color)
                                    ->formatStateUsing(fn($record): string => $record->health_score_formatted),
                                TextEntry::make('health_score_status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn($record): string => $record->health_score_color),
                                TextEntry::make('urls_with_errors_count')
                                    ->label('URLs with Issues')
                                    ->formatStateUsing(function ($record) {
                                        $total = $record->total_urls_crawled;
                                        $errors = $record->getUrlsWithErrorsCount();

                                        return "{$errors} of {$total} URLs";
                                    })
                                    ->default('Loading...'),
                            ]),
                    ])
                    ->visible(fn($record): bool => $record->isCompleted()),
                Section::make('Issues Summary')
                    ->schema([
                        Grid::make(3)
                            ->schema([
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
                    ]),
            ]);
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\SeoIssuesTableWidget::make([
                'recordId' => $this->getRecord()->id,
            ]),
        ];
    }
}
