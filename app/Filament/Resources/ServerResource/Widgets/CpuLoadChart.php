<?php

    namespace App\Filament\Resources\ServerResource\Widgets;

    use App\Models\ServerInformationHistory;
    use Carbon\Carbon;
    use Filament\Widgets\ChartWidget;

    class CpuLoadChart extends ChartWidget
    {
        protected static ?string $heading = 'CPU Load';
        public $record;

        protected function getData(): array
        {
            app('debugbar')->log($this->record);
            $data = ServerInformationHistory::query()
                ->where('server_id', $this->record->id)
                ->pluck('cpu_load', 'created_at')
            ;

            return [
                'datasets' => [ [
                                    'label'           => ' CPU Usage',
                                    'data'            => $data->values(),
                                    'borderColor'     => 'rgba(75, 192, 192, 1)',
                                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                                    'borderWidth'     => 2,
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
    }
