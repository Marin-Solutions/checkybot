<?php

    namespace App\Filament\Resources\ServerResource\Widgets;

    use App\Filament\Resources\ServerResource\Enums\TimeFrame;
    use App\Models\ServerInformationHistory;
    use Carbon\Carbon;
    use Filament\Widgets\ChartWidget;
    use Illuminate\Support\Facades\DB;
    use Livewire\Attributes\On;

    class RamUsedChart extends ChartWidget
    {
        protected static ?string $heading = 'RAM Used';
        public $record;
        public ?TimeFrame $timeFrame = null;

        protected function getData(): array
        {
            $timeFrame = $this->timeFrame ?? TimeFrame::getDefaultTimeframe();
            $data      = ServerInformationHistory::query()
                ->where('server_id', $this->record->id)
                ->where('created_at', '>=', DB::raw('NOW() - INTERVAL ' . $timeFrame->value))
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
