<?php

namespace App\Filament\Resources\ServerResource\Widgets;

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
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

    public ?array $data = [];

    public function mount(): void
    {
        $this->data = ['timeFrame' => TimeFrame::LAST_24_HOURS->value];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make()
                    ->schema([
                        Select::make('timeFrame')
                            ->options(TimeFrame::getOptionsArray())
                            ->prefix('Show')
                            ->hiddenLabel()
                            ->selectablePlaceholder(false)
                            ->afterStateUpdated(fn (string $state) => $this->dispatch('updateTimeframe', timeFrame: TimeFrame::from($state)))
                            ->columnStart(['sm' => 2, 'xl' => 3])->columns(1)
                            ->live(),
                    ])->columns(['sm' => 2, 'xl' => 3]),
            ]);
    }
}
