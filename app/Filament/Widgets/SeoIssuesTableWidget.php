<?php

namespace App\Filament\Widgets;

use App\Models\SeoIssue;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
        // Only show on SEO check detail pages (when a record ID is present)
        $route = request()->route();

        return $route && $route->parameter('record') !== null;
    }

    public function mount(): void
    {
        // The recordId will be passed from the parent page
    }

    #[On('seo-check-finished')]
    public function refreshWidget(): void
    {
        // Refresh the widget data when SEO check completes
        // Use a safe refresh that doesn't break Alpine.js bindings
        $this->dispatch('$refresh');
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

        // Get the record
        $record = $recordId ? \App\Models\SeoCheck::find($recordId) : null;

        // Set up query - use empty query if no record found
        $query = $record
            ? $record->seoIssues()->with('seoCrawlResult')->getQuery()
            : \App\Models\SeoIssue::query()->whereRaw('1 = 0');

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn ($state): string => match ($state->value) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        'notice' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state): string => strtoupper($state->value))
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Issue Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => ucwords(str_replace('_', ' ', $state)))
                    ->sortable(),
                TextColumn::make('url')
                    ->label('URL')
                    ->url(fn ($state) => $state)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Issue')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(80)
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view_issue_details')
                    ->label('View Details')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->modalHeading(fn (SeoIssue $record): string => $record->title)
                    ->modalDescription('Evidence, affected URLs, stored data, and practical fix guidance for this SEO issue.')
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Section::make('Issue Overview')
                            ->schema([
                                TextEntry::make('severity')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => strtoupper($state->value))
                                    ->color(fn ($state): string => match ($state->value) {
                                        'error' => 'danger',
                                        'warning' => 'warning',
                                        'notice' => 'info',
                                        default => 'gray',
                                    }),
                                TextEntry::make('type')
                                    ->label('Issue Type')
                                    ->badge()
                                    ->color('gray')
                                    ->formatStateUsing(fn (?string $state): string => $state ? ucwords(str_replace('_', ' ', $state)) : '-'),
                                TextEntry::make('url')
                                    ->label('Flagged URL')
                                    ->copyable()
                                    ->columnSpanFull(),
                                TextEntry::make('description')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make('Fix Guidance')
                            ->schema([
                                TextEntry::make('fix_guidance')
                                    ->label('')
                                    ->state(fn (SeoIssue $record): array => $record->getFixGuidance())
                                    ->bulleted()
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Evidence')
                            ->schema([
                                RepeatableEntry::make('evidence_items')
                                    ->label('')
                                    ->state(fn (SeoIssue $record): array => $record->getEvidenceItems())
                                    ->schema([
                                        TextEntry::make('label')
                                            ->badge()
                                            ->color('gray'),
                                        TextEntry::make('value')
                                            ->copyable()
                                            ->columnSpanFull(),
                                    ])
                                    ->contained(false)
                                    ->columns(1),
                            ]),
                        Section::make('Affected URLs')
                            ->schema([
                                RepeatableEntry::make('affected_urls')
                                    ->label('')
                                    ->state(fn (SeoIssue $record): array => $record->getAffectedUrls())
                                    ->schema([
                                        TextEntry::make('label')
                                            ->badge()
                                            ->color('gray'),
                                        TextEntry::make('url')
                                            ->copyable()
                                            ->url(fn (?string $state): ?string => $state)
                                            ->openUrlInNewTab()
                                            ->columnSpanFull(),
                                    ])
                                    ->contained(false)
                                    ->columns(1),
                            ]),
                        Section::make('Stored Data')
                            ->hidden(fn (SeoIssue $record): bool => blank($record->data))
                            ->schema([
                                KeyValueEntry::make('data')
                                    ->label('')
                                    ->state(fn (SeoIssue $record): array => $record->getStoredDataForDisplay())
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'error' => 'Error (Critical)',
                        'warning' => 'Warning (Medium)',
                        'notice' => 'Notice (Low)',
                    ])
                    ->placeholder('All severities'),
                SelectFilter::make('type')
                    ->options([
                        'broken_internal_link' => 'Broken Internal Links',
                        'redirect_chain' => 'Redirect Chains',
                        'canonical_issue' => 'Canonical Issues',
                        'https_issue' => 'HTTPS Issues',
                        'orphaned_page' => 'Orphaned Pages',
                        'duplicate_content' => 'Duplicate Content',
                        'duplicate_meta_description' => 'Duplicate Meta Descriptions',
                        'missing_meta_description' => 'Missing Meta Descriptions',
                        'missing_h1' => 'Missing H1 Tags',
                        'duplicate_h1' => 'Duplicate H1 Tags',
                        'large_images' => 'Large Images',
                        'slow_response' => 'Slow Response Times',
                        'missing_alt_text' => 'Missing Alt Text',
                        'short_title' => 'Short Titles',
                        'long_title' => 'Long Titles',
                        'few_internal_links' => 'Few Internal Links',
                    ])
                    ->placeholder('All issue types')
                    ->searchable(),
            ])
            ->defaultSort('severity')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchable();
    }
}
