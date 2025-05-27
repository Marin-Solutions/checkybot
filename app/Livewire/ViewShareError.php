<?php

    namespace App\Livewire;

    use App\Models\ErrorReportPublicLink;
    use App\Traits\ErsHasErrorRequestInfolist;
    use Filament\Forms\Concerns\InteractsWithForms;
    use Filament\Forms\Contracts\HasForms;
    use Filament\Infolists\Components\Section;
    use Filament\Infolists\Components\Split;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Concerns\InteractsWithInfolists;
    use Filament\Infolists\Contracts\HasInfolists;
    use Filament\Infolists\Infolist;
    use Filament\Pages\BasePage;
    use Illuminate\Database\Eloquent\Model;
    use Livewire\Attributes\Locked;

    class ViewShareError extends BasePage implements HasForms, HasInfolists
    {
        use InteractsWithInfolists;
        use InteractsWithForms;
        use ErsHasErrorRequestInfolist;

        #[Locked]
        public Model|int|string|null $errorToken;

        #[Locked]
        public Model|int|string|null $error;

        #[Locked]
        public array $errorStacktrace;

        public function hasLogo()
        {
            return false;
        }

        protected static string $view = 'livewire.view-share-error';

        public function mount( $error_token ): void
        {
            $this->errorToken = ErrorReportPublicLink::query()->firstWhere('token', $error_token);

            $this->error = $this->errorToken->errorReport;
        }


        public function errorInfolist( Infolist $infolist ): Infolist
        {
            return $infolist
                ->record($this->error)
                ->schema([
                    Section::make()
                        ->schema([
                            Split::make([
                                TextEntry::make('exception_class')
                                    ->label('')
                                    ->badge()
                                    ->color('danger'),
                                TextEntry::make('language')
                                    ->label('')
                                    ->formatStateUsing(fn( $state, $record ) => $state . ' ' . $record->language_version)
                                    ->color('gray')
                                    ->grow(false),
                                TextEntry::make('framework_version')
                                    ->label('')
                                    ->formatStateUsing(fn( $state, $record ) => ucfirst($record->projects->technology) . ' ' . $state)
                                    ->color('gray')
                                    ->grow(false),
                            ]),
                            TextEntry::make('message')
                                ->label('')
                                ->size(TextEntry\TextEntrySize::Medium)
                                ->copyable()
                                ->copyMessage('Copied!')
                                ->copyMessageDuration(1500)
                        ])
                ])
            ;
        }

    }
