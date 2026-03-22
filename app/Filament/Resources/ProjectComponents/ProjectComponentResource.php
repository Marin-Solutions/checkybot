<?php

namespace App\Filament\Resources\ProjectComponents;

use App\Filament\Resources\ProjectComponents\Pages\CreateProjectComponent;
use App\Filament\Resources\ProjectComponents\Pages\EditProjectComponent;
use App\Filament\Resources\ProjectComponents\Pages\ListProjectComponents;
use App\Filament\Resources\ProjectComponents\Pages\ViewProjectComponent;
use App\Filament\Resources\ProjectComponents\RelationManagers\HeartbeatsRelationManager;
use App\Filament\Resources\ProjectComponents\Schemas\ProjectComponentForm;
use App\Filament\Resources\ProjectComponents\Schemas\ProjectComponentInfolist;
use App\Filament\Resources\ProjectComponents\Tables\ProjectComponentsTable;
use App\Models\ProjectComponent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectComponentResource extends Resource
{
    protected static ?string $model = ProjectComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Application Components';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('created_by', auth()->id())
            ->with(['project']);
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectComponentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectComponentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectComponentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            HeartbeatsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjectComponents::route('/'),
            'create' => CreateProjectComponent::route('/create'),
            'view' => ViewProjectComponent::route('/{record}'),
            'edit' => EditProjectComponent::route('/{record}/edit'),
        ];
    }
}
