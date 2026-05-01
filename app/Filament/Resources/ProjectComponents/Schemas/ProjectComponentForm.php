<?php

namespace App\Filament\Resources\ProjectComponents\Schemas;

use App\Models\Project;
use App\Models\ProjectComponent;
use App\Support\HealthStatusLabel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProjectComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Component')
                    ->schema([
                        Select::make('project_id')
                            ->label('Application')
                            ->options(fn (): array => Project::query()
                                ->where('created_by', auth()->id())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->autofocus()
                            ->scopedUnique(
                                model: ProjectComponent::class,
                                ignoreRecord: true,
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->where('project_id', $get('project_id')),
                            )
                            ->maxLength(255)
                            ->helperText('Use the exact cron job, worker, or process name your heartbeat will report.'),
                        TextInput::make('declared_interval')
                            ->label('Interval')
                            ->required()
                            ->maxLength(50)
                            ->placeholder('5m')
                            ->helperText('How often Checkybot should expect a heartbeat, for example 5m, 2h, or 1d.')
                            ->regex('/^([1-9]\d*[smhd]|every_[1-9]\d*_(second|seconds|minute|minutes|hour|hours|day|days))$/'),
                        Select::make('current_status')
                            ->label('Status')
                            ->options(fn (?ProjectComponent $record): array => $record === null
                                ? ['unknown' => HealthStatusLabel::format('unknown')]
                                : HealthStatusLabel::options(includeUnknown: $record->last_heartbeat_at === null))
                            ->default('unknown')
                            ->required(),
                        Toggle::make('is_archived')
                            ->label('Archived')
                            ->helperText('Archived components keep history but do not fire stale heartbeat alerts.')
                            ->default(false)
                            ->inline(false),
                    ])
                    ->columns(2),
            ]);
    }
}
