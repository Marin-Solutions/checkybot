<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

class SeoIssuesTableWidget extends BaseWidget
{
    protected static ?string $heading = 'SEO Issues';

    protected int|string|array $columnSpan = 'full';

    public ?int $recordId = null;

    public static function canView(): bool
    {
        // Always show the widget - let the parent page control visibility
        return true;
    }

    public function mount(): void
    {
        // The recordId will be passed from the parent page
    }

    #[On('seo-check-finished')]
    public function handleSeoCheckFinished(): void
    {
        // Refresh the table when SEO check completes
        // Use a safer method that doesn't require table initialization
        try {
            // Force a re-render by updating a property
            $this->recordId = $this->recordId; // Trigger re-render
        } catch (\Exception $e) {
            // Silently handle refresh errors to prevent breaking the UI
            \Log::warning('SEO Issues Table refresh failed on completion: ' . $e->getMessage());
        }
    }

    #[On('refresh-seo-check-data')]
    public function handleProgressUpdate(): void
    {
        // Disable real-time table updates during crawling to prevent initialization errors
        // The table will be refreshed on completion instead
        // This prevents the "table must not be accessed before initialization" error

    }

    public function table(Table $table): Table
    {
        // Get recordId from route parameter
        $route = request()->route();
        $recordId = $route->parameter('record') ?? $this->recordId;

        // If no recordId, return empty query
        if (! $recordId) {
            return $table->query(\App\Models\SeoIssue::query()->whereRaw('1 = 0'));
        }

        $record = \App\Models\SeoCheck::find($recordId);
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
            ])
            ->defaultSort('severity')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
