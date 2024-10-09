<?php

    namespace App\Filament\Resources\ServerResource\Widgets;

    use App\Models\ServerInformationHistory;
    use Carbon\Carbon;
    use Filament\Widgets\ChartWidget;

    class RamUsedChart extends ChartWidget
    {
        protected static ?string $heading = 'RAM Used';
        public $record;

        protected function getData(): array
        {
            $data = ServerInformationHistory::query()
                ->where('server_id', $this->record->id)
                ->pluck('ram_free_percentage', 'created_at')
            ;

            return [
                'datasets' => [ [
                                    'label'           => ' CPU Usage',
                                    'data'            => $data->values()->map(function ( $free ) {
                                        $free = floatval(str_replace('%', '', $free));
                                        return 100 - $free;
                                    }),
                                    'borderColor'     => 'rgba(0, 123, 255, 1)',
                                    'backgroundColor' => 'rgba(0, 123, 255, 0.2)',
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
