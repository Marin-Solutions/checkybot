<?php

namespace App\Filament\Resources\ServerResource\Widgets;

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use App\Models\ServerInformationHistory;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        $granularitySeconds = $timeFrame->getGranularityMinutes() * 60;

        $data = ServerInformationHistory::query()
            ->select([
                DB::raw("FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(created_at) / {$granularitySeconds}) * {$granularitySeconds}) as time_bucket"),
                DB::raw('AVG(cpu_load) as avg_value'),
            ])
            ->where('server_id', $this->record->id)
            ->where('created_at', '>=', $timeFrame->getStartDate())
            ->groupBy('time_bucket')
            ->orderBy('time_bucket')
            ->get();

        return [
            'datasets' => [[
                'label' => ' CPU Usage',
                'data' => $data->pluck('avg_value')->map(fn ($v) => round((float) $v, 2))->toArray(),
                'borderColor' => 'rgba(75, 192, 192, 1)',
                'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'tension' => 0.4,
                'fill' => 'origin',
            ]],
            'labels' => $data->pluck('time_bucket')->map(fn ($date) => Carbon::parse($date)->toIso8601String())->toArray(),
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
