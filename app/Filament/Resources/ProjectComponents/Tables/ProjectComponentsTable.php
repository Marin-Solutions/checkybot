<?php

namespace App\Filament\Resources\ProjectComponents\Tables;

use App\Models\ProjectComponent;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ProjectComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('project.name')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('current_status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('archive_state')
                    ->label('State')
                    ->state(fn (ProjectComponent $record): string => $record->is_archived ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                TextColumn::make('declared_interval')
                    ->label('Interval'),
                TextColumn::make('last_heartbeat_at')
                    ->since(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('Enable')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Enable selected components')
                        ->modalDescription('Selected components will be un-archived and will resume tracking incoming heartbeats.')
                        ->modalSubmitActionLabel('Enable')
                        ->action(function (Collection $records): void {
                            $count = $records->where('is_archived', true)->count();

                            $records->each(function (ProjectComponent $component): void {
                                if ($component->is_archived) {
                                    $component->forceFill([
                                        'is_archived' => false,
                                        'archived_at' => null,
                                    ])->save();
                                }
                            });

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to enable'
                                    : ($count === 1 ? '1 component enabled' : "{$count} components enabled"))
                                ->body($count === 0
                                    ? 'Every selected component was already active.'
                                    : 'Heartbeat tracking has resumed for these components.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disable')
                        ->label('Disable')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Disable selected components')
                        ->modalDescription('Selected components will be archived. Incoming heartbeats are ignored and stale alerts will stop firing, but history is preserved.')
                        ->modalSubmitActionLabel('Disable')
                        ->action(function (Collection $records): void {
                            $count = $records->where('is_archived', false)->count();

                            $records->each(function (ProjectComponent $component): void {
                                if (! $component->is_archived) {
                                    $component->forceFill([
                                        'is_archived' => true,
                                        'archived_at' => now(),
                                    ])->save();
                                }
                            });

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to disable'
                                    : ($count === 1 ? '1 component disabled' : "{$count} components disabled"))
                                ->body($count === 0
                                    ? 'Every selected component was already archived.'
                                    : 'These components are now archived and will no longer fire stale alerts.')
                                ->warning()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
