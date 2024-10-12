<?php

    namespace App\Filament\Resources\ServerResource\Widgets;

    use App\Filament\Resources\ServerResource\Enums\TimeFrame;
    use App\Models\ServerInformationHistory;
    use Carbon\Carbon;
    use Filament\Widgets\ChartWidget;
    use Illuminate\Support\Facades\DB;
    use Livewire\Attributes\On;

    class CpuLoadChart extends ChartWidget
    {
        protected static ?string $heading = 'CPU Load';
        public $record;
        public ?TimeFrame $timeFrame = null;

        protected function getData(): array
        {
            $timeFrame = $this->timeFrame ?? TimeFrame::getDefaultTimeframe();
            $data      = ServerInformationHistory::query()
                ->where('server_id', $this->record->id)
                ->where('created_at', '>=', DB::raw('NOW() - INTERVAL ' . $timeFrame->value))
                ->pluck('cpu_load', 'created_at')
            ;

            return [
                'datasets' => [ [
                                    'label'           => ' CPU Usage',
                                    'data'            => $data->values(),
                                    'borderColor'     => 'rgba(75, 192, 192, 1)',
                                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                                    'borderWidth'     => 2,
                                    'pointRadius'     => 0,
                                    'tension'         => 0.4,
                                    'fill'            => 'origin',
                                ] ],
                'labels'   => $data->keys()->map(function ( $date ) {
                    return Carbon::parse($date);
                }),
            ];
        }

        protected function getType(): string
        {
            return 'line';
        }

        protected static string $view = 'components/cpu-load-chart-widget';

        #[On( 'updateTimeframe' )]
        public function updateTimeframe( TimeFrame $timeFrame ): void
        {
            $this->timeFrame = $timeFrame;
            $this->updateChartData();
        }
    }
