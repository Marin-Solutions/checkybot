<?php

namespace App\Filament\Resources\BackupsResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class HistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'Backup History';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->columns([
                TextColumn::make('is_zipped')
                    ->label('Zip')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Created' : 'Failed')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextColumn::make('is_uploaded')
                    ->label('Upload')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Uploaded' : 'Failed')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextColumn::make('filename')
                    ->label('Filename')
                    ->searchable()
                    ->wrap()
                    ->copyable(),
                TextColumn::make('filesize')
                    ->label('File Size')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? Number::fileSize($state) : '-')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('message')
                    ->label('Message')
                    ->placeholder('-')
                    ->limit(90)
                    ->wrap()
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('created_at')
                    ->label('Captured At')
                    ->dateTimeInUserZone()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No backup runs logged yet')
            ->emptyStateDescription('Once the scheduled backup script reports back, zip and upload results will appear here.')
            ->paginated([10, 25, 50])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
