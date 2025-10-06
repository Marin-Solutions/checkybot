<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class SeoIssuesTableWidget extends BaseWidget
{
    protected static ?string $heading = 'SEO Issues';

    protected int|string|array $columnSpan = 'full';

    public ?int $recordId = null;

    public static function canView(): bool
    {
        return false;
    }

    public function mount(): void
    {
        // The recordId will be passed from the parent page
    }

    public function table(Table $table): Table
    {
        if (! $this->recordId) {
            return $table->query(\App\Models\SeoIssue::query()->whereRaw('1 = 0'));
        }

        $record = \App\Models\SeoCheck::find($this->recordId);
        if (! $record) {
            return $table->query(\App\Models\SeoIssue::query()->whereRaw('1 = 0'));
        }

        return $table
            ->query($record->seoIssues()->getQuery())
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
                SelectFilter::make('title')
                    ->label('Filter by Issue Title')
                    ->options(function () {
                        $record = \App\Models\SeoCheck::find($this->recordId);
                        if (! $record) {
                            return [];
                        }

                        return $record->seoIssues()
                            ->distinct()
                            ->pluck('title', 'title')
                            ->toArray();
                    })
                    ->placeholder('All issue titles'),
                SelectFilter::make('description')
                    ->label('Filter by Description')
                    ->options(function () {
                        $record = \App\Models\SeoCheck::find($this->recordId);
                        if (! $record) {
                            return [];
                        }

                        return $record->seoIssues()
                            ->distinct()
                            ->pluck('description', 'description')
                            ->toArray();
                    })
                    ->placeholder('All descriptions'),
            ])
            ->defaultSort('severity')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
