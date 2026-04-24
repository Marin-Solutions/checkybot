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

    /**
     * Get the navigation badge for the resource.
     *
     * Shows "unhealthy/total" when any component is in warning or danger state
     * so the sidebar surfaces broken heartbeats at a glance.
     */
    public static function getNavigationBadge(): ?string
    {
        $baseQuery = static::getModel()::query()->where('created_by', auth()->id());

        $total = (clone $baseQuery)->count();
        $unhealthy = (clone $baseQuery)
            ->whereIn('current_status', ['warning', 'danger'])
            ->count();

        if ($unhealthy > 0) {
            return $unhealthy.'/'.number_format($total);
        }

        return number_format($total);
    }

    /**
     * Color the navigation badge danger whenever any component is unhealthy.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        $hasUnhealthy = static::getModel()::query()
            ->where('created_by', auth()->id())
            ->whereIn('current_status', ['warning', 'danger'])
            ->exists();

        return $hasUnhealthy ? 'danger' : null;
    }

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
