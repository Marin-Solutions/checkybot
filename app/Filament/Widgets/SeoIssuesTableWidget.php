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
    public function refreshWidget(): void
    {
        // Force re-render of the widget when SEO check completes
        $this->resetTable();
    }

    protected function getPollingInterval(): ?string
    {
        // Check if the SEO check just completed and needs a one-time refresh
        $route = request()->route();
        $recordId = $route->parameter('record') ?? $this->recordId;

        if ($recordId) {
            $record = \App\Models\SeoCheck::find($recordId);

            // If completed within the last 10 seconds, poll once to refresh the table
            if ($record && $record->isCompleted() && $record->finished_at) {
                $secondsSinceCompletion = now()->diffInSeconds($record->finished_at);

                if ($secondsSinceCompletion < 10) {
                    return '2s'; // Poll every 2 seconds for the first 10 seconds after completion
                }
            }
        }

        return null; // No polling otherwise
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
