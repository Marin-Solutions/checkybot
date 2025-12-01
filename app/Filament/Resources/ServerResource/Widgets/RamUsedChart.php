<?php

namespace App\Filament\Resources\ServerResource\Widgets;

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;

class RamUsedChart extends ChartWidget
{
    protected ?string $heading = 'RAM Used';

    public ?Server $record = null;

    public ?TimeFrame $timeFrame = null;

    protected function getData(): array
    {
        if (! $this->record) {
            return ['datasets' => [], 'labels' => []];
        }

        $timeFrame = $this->timeFrame ?? TimeFrame::getDefaultTimeframe();
        $data = ServerInformationHistory::query()
            ->where('server_id', $this->record->id)
            ->where('created_at', '>=', $timeFrame->getStartDate())
            ->pluck('ram_free_percentage', 'created_at');

        return [
            'datasets' => [[
                'label' => ' CPU Usage',
                'data' => $data->values()->map(function ($free) {
                    $free = floatval(str_replace('%', '', $free));

                    return 100 - $free;
                }),
                'borderColor' => 'rgba(0, 123, 255, 1)',
                'backgroundColor' => 'rgba(0, 123, 255, 0.2)',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'tension' => 0.4,
                'fill' => 'origin',
            ]],
            'labels' => $data->keys()->map(function ($date) {
                return Carbon::parse($date);
            }),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected string $view = 'components/cpu-load-chart-widget';

    #[On('updateTimeframe')]
    public function updateTimeframe(TimeFrame $timeFrame): void
    {
        $this->timeFrame = $timeFrame;
        $this->updateChartData();
    }
}
