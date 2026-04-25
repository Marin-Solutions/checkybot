<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Models\OutboundLink;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'outboundLinks';

    protected static ?string $title = 'Outbound Links';

    protected static ?string $recordTitleAttribute = 'outgoing_url';

    public function table(Table $table): Table
    {
        $safeUrl = fn (?string $state): ?string => $state && preg_match('#^https?://#i', $state) ? $state : null;

        return $table
            ->columns([
                TextColumn::make('found_on')
                    ->label('Found On')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->url($safeUrl)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->wrap(),
                TextColumn::make('outgoing_url')
                    ->label('Outgoing URL')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->url($safeUrl)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->wrap(),
                TextColumn::make('http_status_code')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 300 => 'info',
                        $state >= 200 => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? (string) $state : '—')
                    ->sortable(),
                TextColumn::make('last_checked_at')
                    ->label('Last Checked')
                    ->since()
                    ->tooltip(fn (?OutboundLink $record): ?string => $record?->last_checked_at?->toDayDateTimeString())
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('http_status_code')
                    ->label('HTTP Status')
                    ->options([
                        'broken' => 'Broken (4xx & 5xx)',
                        'client_error' => 'Client errors (4xx)',
                        'server_error' => 'Server errors (5xx)',
                        'redirect' => 'Redirects (3xx)',
                        'success' => 'Successful (2xx)',
                        'unknown' => 'No response recorded',
                        '404' => '404 Not Found',
                        '500' => '500 Internal Server Error',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'broken' => $query->whereBetween('http_status_code', [400, 599]),
                            'client_error' => $query->whereBetween('http_status_code', [400, 499]),
                            'server_error' => $query->whereBetween('http_status_code', [500, 599]),
                            'redirect' => $query->whereBetween('http_status_code', [300, 399]),
                            'success' => $query->whereBetween('http_status_code', [200, 299]),
                            'unknown' => $query->whereNull('http_status_code'),
                            '404', '500' => $query->where('http_status_code', (int) $value),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('last_checked_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No outbound links recorded yet')
            ->emptyStateDescription('Enable the outbound check on this website to start tracking external links and their HTTP status codes.');
    }
}
