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
        $route = request()->route();
        if ($route && $route->getName() === 'filament.admin.resources.seo-checks.view') {
            return true;
        }

        // Also allow if we're in a context where recordId is being passed
        if (request()->has('recordId') || session()->has('seo_check_record_id')) {
            return true;
        }

        return false;
    }

    public function mount(): void
    {
        // The recordId will be passed from the parent page
    }

    #[On('seo-check-finished')]
    public function handleSeoCheckFinished(): void
    {
        // Refresh the table when SEO check completes
        $this->dispatch('$refresh');
    }

    public function table(Table $table): Table
    {
        // If no recordId is provided, try to get it from the route
        if (! $this->recordId) {
            $route = request()->route();
            if ($route && $route->getName() === 'filament.admin.resources.seo-checks.view') {
                $this->recordId = $route->parameter('record');
            }
        }

        // If still no recordId, return empty query (this prevents showing on dashboard)
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
            ])
            ->defaultSort('severity')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
