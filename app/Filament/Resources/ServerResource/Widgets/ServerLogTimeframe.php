<?php

namespace App\Filament\Resources\ServerResource\Widgets;

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;

class ServerLogTimeframe extends Widget implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.resources.server-resource.widgets.server-log-timeframe';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public ?array $data = ['timeFrame' => TimeFrame::LAST_24_HOURS];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Grid::make()
                    ->schema([
                        Select::make('timeFrame')
                            ->options(TimeFrame::getOptionsArray())
                            ->prefix('Show')
                            ->hiddenLabel()
                            ->selectablePlaceholder(false)
                            ->afterStateUpdated(fn(TimeFrame $state) => $this->dispatch('updateTimeframe', timeFrame: $state))
                            ->columnStart(['sm' => 2, 'xl' => 3])->columns(1)
                            ->live(),
                    ])->columns(['sm' => 2, 'xl' => 3]),
            ]);
    }
}
