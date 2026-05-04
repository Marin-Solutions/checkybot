<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Enums\RunSource;
use App\Models\WebsiteLogHistory;
use App\Support\UptimeTransportError;
use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'logHistory';

    protected static ?string $title = 'Run History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('run_source')
                    ->label('Run')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                    ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                TextColumn::make('http_status_code')
                    ->label('HTTP'),
                TextColumn::make('transport_error_type')
                    ->label('Transport Error')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                    ->color(fn (?string $state): string => UptimeTransportError::color($state))
                    ->placeholder('-'),
                TextColumn::make('transport_error_message')
                    ->label('Transport Evidence')
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-'),
                TextColumn::make('speed')
                    ->label('Response Time')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state}ms" : '-'),
                TextColumn::make('summary')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('created_at')
                    ->dateTimeInUserZone()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'danger' => 'Danger',
                    ]),
                Tables\Filters\SelectFilter::make('run_source')
                    ->label('Run')
                    ->options(RunSource::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->label('View Evidence')
                    ->modalWidth('4xl'),
            ])
            ->toolbarActions([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run Overview')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => static::statusColor($state)),
                        TextEntry::make('run_source')
                            ->label('Run')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                            ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                        TextEntry::make('http_status_code')
                            ->label('HTTP')
                            ->badge()
                            ->formatStateUsing(fn (?int $state): string => $state === 0 ? 'No response' : (string) ($state ?? '-'))
                            ->color(fn (?int $state): string => static::httpCodeColor($state)),
                        TextEntry::make('speed')
                            ->label('Response Time')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-'),
                        TextEntry::make('created_at')
                            ->label('Captured At')
                            ->dateTimeInUserZone(),
                        TextEntry::make('summary')
                            ->columnSpanFull()
                            ->default('-'),
                    ])
                    ->columns(3),
                Section::make('SSL Evidence')
                    ->hidden(fn (WebsiteLogHistory $record): bool => blank($record->ssl_expiry_date))
                    ->schema([
                        TextEntry::make('ssl_expiry_date')
                            ->label('Certificate Expiry')
                            ->badge()
                            ->state(fn (WebsiteLogHistory $record): ?string => static::formatSslDate($record->ssl_expiry_date))
                            ->color(fn (WebsiteLogHistory $record): string => static::sslExpiryColor($record->ssl_expiry_date))
                            ->hint(fn (WebsiteLogHistory $record): ?string => static::sslExpiryHint($record->ssl_expiry_date)),
                        TextEntry::make('ssl_summary')
                            ->label('Summary')
                            ->state(fn (WebsiteLogHistory $record): string => $record->summary ?: '-')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Transport Evidence')
                    ->hidden(fn (WebsiteLogHistory $record): bool => blank($record->transport_error_type))
                    ->schema([
                        TextEntry::make('transport_error_type')
                            ->label('Classification')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->color(fn (?string $state): string => UptimeTransportError::color($state)),
                        TextEntry::make('transport_error_code')
                            ->label('cURL Error Code')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('transport_error_message')
                            ->label('Message')
                            ->placeholder('-')
                            ->copyable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function statusColor(?string $state): string
    {
        return match ($state) {
            'healthy' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'gray',
        };
    }

    private static function httpCodeColor(?int $code): string
    {
        if ($code === null) {
            return 'gray';
        }

        return match (true) {
            $code <= 0 => 'danger',
            $code >= 200 && $code < 300 => 'success',
            $code >= 300 && $code < 400 => 'info',
            $code >= 400 && $code < 500 => 'warning',
            $code >= 500 => 'danger',
            default => 'danger',
        };
    }

    private static function formatSslDate(mixed $value): ?string
    {
        $date = static::toCarbon($value);

        return $date?->toFormattedDateString();
    }

    private static function sslExpiryColor(mixed $value): string
    {
        $date = static::toCarbon($value);

        if ($date === null) {
            return 'gray';
        }

        $daysRemaining = static::daysUntilExpiry($date);

        return match (true) {
            $daysRemaining < 0 => 'danger',
            $daysRemaining <= 14 => 'warning',
            default => 'success',
        };
    }

    private static function sslExpiryHint(mixed $value): ?string
    {
        $date = static::toCarbon($value);

        if ($date === null) {
            return null;
        }

        $daysRemaining = static::daysUntilExpiry($date);

        if ($daysRemaining === 0) {
            return 'Expires today';
        }

        if ($daysRemaining === 1) {
            return 'Expires tomorrow';
        }

        if ($daysRemaining === -1) {
            return 'Expired yesterday';
        }

        return $daysRemaining < 0
            ? 'Expired '.abs($daysRemaining).' days ago'
            : "Expires in {$daysRemaining} days";
    }

    private static function daysUntilExpiry(Carbon $expiryDate): int
    {
        $today = now()->startOfDay();
        $expiry = $expiryDate->copy()->startOfDay();

        return (int) round($today->diffInDays($expiry, false));
    }

    private static function toCarbon(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
