<?php

namespace App\Filament\Resources\ProjectsResource\RelationManagers;

use App\Filament\Resources\ProjectsResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ErrorReportedRelationManager extends RelationManager
{
    protected static string $relationship = 'errorReported';

    protected static ?string $title = "Errors";

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->error_reported_count > 0;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('exception_class')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('exception_class')
            ->modifyQueryUsing(fn(Builder $query) => $query->select([
                'id',
                'project_id',
                'exception_class',
                'message',
                'seen_at',
                'created_at',
                'updated_at'
            ])->orderBy('exception_class'))
            ->columns([
                Tables\Columns\TextColumn::make('exception_class')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('danger'),
                Tables\Columns\TextColumn::make('message')
                    ->lineClamp(1)
                    ->limit(64)
                    ->searchable()
                    ->placeholder('No message'),
                Tables\Columns\TextColumn::make('seen_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Unknown'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('recent')
                    ->query(fn(Builder $query): Builder => $query->where('created_at', '>=', now()->subDays(7)))
                    ->label('Last 7 days'),
                Tables\Filters\Filter::make('has_message')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('message'))
                    ->label('Has message'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('view')
                    ->action(function ($record) {
                        $this->redirect(ProjectsResource::getUrl('view-error', ['record' => $this->ownerRecord, 'error' => $record]));
                    })
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->groups([
                'exception_class'
            ])
            ->defaultGroup('exception_class')
            ->defaultSort('exception_class')
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10)
        ;
    }
}
