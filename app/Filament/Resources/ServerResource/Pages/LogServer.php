<?php

    namespace App\Filament\Resources\ServerResource\Pages;

    use App\Filament\Resources\ServerResource;
    use Filament\Actions;
    use Filament\Forms\Form;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Infolist;
    use Filament\Resources\Pages\ViewRecord;

    class LogServer extends ViewRecord
    {
        protected static string $resource = ServerResource::class;
        protected static ?string $breadcrumb = 'Log';
        protected static ?string $title = 'Server Log';

        protected function getHeaderWidgets(): array
        {
            return [
                ServerResource\Widgets\ServerLogTimeframe::class,
                ServerResource\Widgets\CpuLoadChart::class,
                ServerResource\Widgets\RamUsedChart::class,
                ServerResource\Widgets\DiskUsedChart::class
            ];
        }

        public function form( Form $form ): Form
        {
            return $form->schema([]);
        }

        protected function getHeaderActions(): array
        {
            return [
                Actions\Action::make('back')
                    ->url(fn() => url()->previous() ?? $this->getResource()::getUrl('index'))
                    ->color('secondary')
            ];
        }
    }
