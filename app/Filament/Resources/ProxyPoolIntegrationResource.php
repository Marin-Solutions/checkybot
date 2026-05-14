<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProxyPoolIntegrationResource\Pages;
use App\Models\Project;
use App\Models\ProxyPoolIntegration;
use App\Services\ProxyPoolDashboardService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProxyPoolIntegrationResource extends Resource
{
    protected static ?string $model = ProxyPoolIntegration::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Proxy Pools';

    protected static ?int $navigationSort = 8;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('created_by', auth()->id())
            ->with('project');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('This is the label shown in dashboard widgets and application components.'),
                Forms\Components\Select::make('project_id')
                    ->label('Application')
                    ->options(fn (): array => Project::query()
                        ->where('created_by', auth()->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('base_url')
                    ->label('Base URL')
                    ->placeholder('https://proxy.example.com')
                    ->url()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('token')
                    ->label('REST API token')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(4000)
                    ->helperText('Leave blank when editing to keep the existing token. The proxy pool API requires this token as a query parameter.'),
                Forms\Components\TextInput::make('check_interval')
                    ->label('Check interval')
                    ->default('5m')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^([1-9]\d*[smhd]|every_[1-9]\d*_(second|seconds|minute|minutes|hour|hours|day|days))$/')
                    ->helperText('How often Checkybot expects a fresh proxy pool sync, for example 5m, 15m, or 1h.'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Application')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_url')
                    ->label('Base URL')
                    ->limit(45)
                    ->tooltip(fn (ProxyPoolIntegration $record): string => $record->base_url),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('last_sync_status')
                    ->label('Last Status')
                    ->badge()
                    ->placeholder('Not synced')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->sinceInUserZone()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('last_sync_error')
                    ->label('Last Error')
                    ->placeholder('-')
                    ->limit(70)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Actions\Action::make('sync')
                    ->label('Sync now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (ProxyPoolIntegration $record): void {
                        $component = app(ProxyPoolDashboardService::class)->syncIntegration($record);

                        Notification::make()
                            ->title('Proxy pool synced')
                            ->body("{$component->name} is {$component->current_status}: {$component->summary}")
                            ->success()
                            ->send();
                    }),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No proxy pools configured')
            ->emptyStateDescription('Add a proxy pool REST API so Checkybot can show renewals, slow proxies, and unhealthy proxies in the dashboard.')
            ->emptyStateIcon('heroicon-o-globe-alt');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProxyPoolIntegrations::route('/'),
            'create' => Pages\CreateProxyPoolIntegration::route('/create'),
            'edit' => Pages\EditProxyPoolIntegration::route('/{record}/edit'),
        ];
    }
}
