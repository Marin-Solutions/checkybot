<?php

    namespace App\Filament\Resources\ProjectsResource\Pages;

    use App\Filament\Resources\ProjectsResource;
    use App\Models\ErrorReportPublicLink;
    use App\Models\ErrorReports;
    use App\Traits\ErsHasErrorRequestInfolist;
    use Filament\Actions\Action;
    use Filament\Infolists\Components\Section;
    use Filament\Infolists\Components\Split;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Concerns\InteractsWithInfolists;
    use Filament\Infolists\Contracts\HasInfolists;
    use Filament\Infolists\Infolist;
    use Filament\Resources\Pages\Concerns\InteractsWithRecord;
    use Filament\Resources\Pages\Page;
    use Illuminate\Contracts\Support\Htmlable;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;
    use Livewire\Attributes\Locked;
    use Webbingbrasil\FilamentCopyActions\Pages\Actions\CopyAction;

    class ViewProjectsError extends Page implements HasInfolists
    {
        use InteractsWithRecord;
        use InteractsWithInfolists;
        use ErsHasErrorRequestInfolist;

        protected static string $resource = ProjectsResource::class;

        protected static string $view = 'filament.resources.projects-resource.pages.projects-error';

        public function getBreadcrumbs(): array
        {
            return [
                self::$resource::getUrl()                          => self::$resource::getBreadcrumb(),
                self::$resource::getUrl('view', [ $this->record ]) => 'View',
                'Error'
            ];
        }

        public function getTitle(): string|Htmlable
        {
            return $this->record->name;
        }

        #[Locked]
        public Model|int|string|null $error;

        public ?array $data = [];

        public function mount( int|string $record ): void
        {
            $this->record = $this->resolveRecord($record);
            $this->error  = ErrorReports::query()->findOrFail(Route::current()->parameter('error'));
            $this->form->fill();
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

        protected function getHeaderActions(): array
        {
            return [
                Action::make('Resolve')
                    ->icon('heroicon-o-wrench')
                    ->color(function () {
                        return $this->error->is_resolved ? 'danger' : 'success';
                    })
                    ->label(function () {
                        return "Mark as " . ( $this->error->is_resolved ? 'unresolved' : 'resolved' );
                    })
                    ->action(function () {
                        $this->error->is_resolved = !$this->error->is_resolved;
                        $this->error->save();
                    })
                    ->requiresConfirmation(),
                CopyAction::make()
                    ->copyable(function ( $record ) {
                        $publicLink = ErrorReportPublicLink::create([
                            'error_report_id' => $this->error->id,
                            'created_by'      => auth()->user()->id,
                            'token'           => Str::uuid()->toString()
                        ]);
                        \Log::info("Public link created for error report: {$publicLink->token}");

                        return \route('share-error', [ 'error_token' => $publicLink->token ]);
                    })
                    ->label('Share')
                    ->icon('heroicon-o-arrow-uturn-right')
            ];
        }

    }
