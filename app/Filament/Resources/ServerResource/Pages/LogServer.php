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
                    ->requiresConfirmation()
                    ->modalHeading('Copy Installation Script')
                    ->modalDescription('This will copy the installation script to your clipboard.')
                    ->modalSubmitActionLabel('Copy')
                    ->modalIcon('heroicon-o-clipboard')
                    ->action(function () {
                        $script = ServerInformationHistory::copyCommand($this->record->id);
                        $this->js("navigator.clipboard.writeText('{$script}')");
                        Notification::make()->success()->title('Script copied to clipboard')->send();
                    }),
                
                Actions\Action::make('copy_log_script')
                    ->label('Copy log script')
                    ->color('gray')
                    ->icon('heroicon-m-clipboard')
                    ->requiresConfirmation()
                    ->modalHeading('Copy Log Script')
                    ->modalDescription('This will copy the log script to your clipboard.')
                    ->modalSubmitActionLabel('Copy')
                    ->modalIcon('heroicon-o-clipboard')
                    ->action(function () {
                        $script = ServerLogFileHistory::copyCommand($this->record->id);
                        $this->js("navigator.clipboard.writeText('{$script}')");
                        Notification::make()->success()->title('Log script copied to clipboard')->send();
                    }),
                
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
