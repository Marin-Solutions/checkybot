<?php

namespace App\Filament\Resources\ServerResource\Widgets;

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use App\Models\ServerInformationHistory;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class RamUsedChart extends ChartWidget
{
    protected ?string $heading = 'RAM Used';

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
            ->pluck('ram_free_percentage', 'created_at');

        return [
            'datasets' => [[
                'label' => ' RAM Usage',
                'data' => $data->values()->map(function ($free) {
                    $free = floatval(str_replace('%', '', $free));

                    return 100 - $free;
                })->toArray(),
                'borderColor' => 'rgba(0, 123, 255, 1)',
                'backgroundColor' => 'rgba(0, 123, 255, 0.2)',
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
