<?php

namespace App\Filament\Resources\ServerResource\Widgets;

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use App\Models\ServerInformationHistory;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class CpuLoadChart extends ChartWidget
{
    protected ?string $heading = 'CPU Load';

    public ?Model $record = null;

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
            ->pluck('cpu_load', 'created_at');

        return [
            'datasets' => [[
                'label' => ' CPU Usage',
                'data' => $data->values()->map(fn ($v) => (float) $v)->toArray(),
                'borderColor' => 'rgba(75, 192, 192, 1)',
                'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'tension' => 0.4,
                'fill' => 'origin',
            ]],
            'labels' => $data->keys()->map(fn ($date) => Carbon::parse($date)->toIso8601String())->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'display' => false,
                ],
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ];
    }

    #[On('updateTimeframe')]
    public function updateTimeframe(TimeFrame $timeFrame): void
    {
        $this->timeFrame = $timeFrame;
        $this->updateChartData();
    }
}
