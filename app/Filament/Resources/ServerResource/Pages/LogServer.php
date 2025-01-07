<?php

    namespace App\Filament\Resources\ServerResource\Pages;

    use App\Filament\Resources\ServerResource;
    use App\Models\ServerInformationHistory;
    use App\Models\ServerLogFileHistory;
    use Filament\Actions;
    use Filament\Forms\Form;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Infolist;
    use Filament\Notifications\Notification;
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
                Actions\Action::make('copy_script')
                    ->label('Copy script')
                    ->color('gray')
                    ->icon('heroicon-m-clipboard')
                    ->extraAttributes([
                        'x-data' => '',
                        'x-on:click' => 'navigator.clipboard.writeText($event.target.getAttribute("data-command"))',
                    ])
                    ->setAttribute('data-command', fn() => ServerInformationHistory::copyCommand($this->record->id))
                    ->action(fn() => Notification::make()->success()->title('Copied to clipboard')->send()),
                
                Actions\Action::make('copy_log_script')
                    ->label('Copy log script')
                    ->color('gray')
                    ->icon('heroicon-m-clipboard')
                    ->extraAttributes([
                        'x-data' => '',
                        'x-on:click' => 'navigator.clipboard.writeText($event.target.getAttribute("data-command"))',
                    ])
                    ->setAttribute('data-command', fn() => ServerLogFileHistory::copyCommand($this->record->id))
                    ->action(fn() => Notification::make()->success()->title('Copied to clipboard')->send()),
                
                Actions\DeleteAction::make()
                    ->modalHeading('Delete Server')
                    ->modalDescription('Are you sure you want to delete this server? This will delete all associated data.')
                    ->successNotificationTitle('Server deleted successfully'),
                
                Actions\Action::make('back')
                    ->url(fn() => url()->previous() ?? $this->getResource()::getUrl('index'))
                    ->color('secondary')
            ];
        }
    }
