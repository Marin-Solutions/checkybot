<?php

    namespace App\Filament\Resources\ProjectsResource\Pages;

    use App\Filament\Resources\ProjectsResource;
    use App\Models\ErrorReportPublicLink;
    use App\Models\ErrorReports;
    use Filament\Actions\Action;
    use Filament\Infolists\Components\Fieldset;
    use Filament\Infolists\Components\KeyValueEntry;
    use Filament\Infolists\Components\RepeatableEntry;
    use Filament\Infolists\Components\Section;
    use Filament\Infolists\Components\Split;
    use Filament\Infolists\Components\Tabs;
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

            $error                   = ErrorReports::query()->findOrFail(Route::current()->parameter('error'));
            $error->request_headers  = $error->context[ 'headers' ];
            $error->request_body     = $error->context[ 'request_data' ][ 'body' ];
            $error->route_name       = $error->context[ 'route' ][ 'route' ];
            $error->route_action     = $error->context[ 'route' ][ 'controllerAction' ];
            $error->route_middleware = $error->context[ 'route' ][ 'middleware' ];
            $error->route_parameters = $error->context[ 'route' ][ 'routeParameters' ];
            $error->queries          = $error->context[ 'queries' ];
            $this->error             = $error;

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

        public function requestInfolist( Infolist $infolist ): Infolist
        {
            return $infolist
                ->record($this->error)
                ->schema([
                    Tabs::make('Tabs')
                        ->tabs([
                            Tabs\Tab::make('Request')
                                ->schema([
                                    TextEntry::make('id')
                                        ->label('Method')
                                        ->formatStateUsing(fn( $state, $record ) => data_get($record, 'context.request.method', '-'))
                                        ->badge()
                                        ->color('gray'),
                                    KeyValueEntry::make('request_headers'),
                                    KeyValueEntry::make('request_body')
                                ]),
                            Tabs\Tab::make('Application')
                                ->schema([
                                    Fieldset::make('Routing')
                                        ->schema([
                                            TextEntry::make('route_action')
                                                ->label('Controller')
                                                ->html()
                                                ->formatStateUsing(function ( $state ) {
                                                    return "<pre class='text-sm'>" . e($state) . "</pre>";
                                                }),
                                            TextEntry::make('route_name')
                                                ->label('Route name')
                                                ->visible(function ( $state ) {
                                                    return !empty($state);
                                                }),
                                            TextEntry::make('route_middleware')
                                                ->label('Middleware')
                                                ->formatStateUsing(fn( $state, $record ) => implode(', ', $record->context[ 'route' ][ 'middleware' ])),
                                            KeyValueEntry::make('route_parameters')
                                                ->label('Parameters')
                                                ->visible(fn( $state ) => !empty($state) || !is_null($state))
                                        ])
                                        ->columns(1),
                                    RepeatableEntry::make('queries')
                                        ->label("Database Queries")
                                        ->schema([
                                            TextEntry::make('connection_name'),
                                            TextEntry::make('time')
                                                ->formatStateUsing(fn( string $state, $record ) => $state . ' ms'),
                                            TextEntry::make('sql')->label('SQL')
                                                ->html()
                                                ->formatStateUsing(function ( $state, $record, $component ) {
                                                    $path  = explode('.', $component->getStatePath())[ 1 ];
                                                    $query = $record->queries[ $path ];
                                                    $sql   = $this->bindQuery($state, $query[ 'bindings' ]);

                                                    return "<pre class='text-sm'>" . e($sql) . "</pre>";
                                                }),
                                        ])
                                        ->columns(2)
                                        ->visible(fn( $state ) => !empty($state) || !is_null($state))
                                ]),
                        ])
                ])
            ;
        }

        protected function bindQuery( $query, $bindings )
        {
            foreach ( $bindings as $binding ) {
                $binding = is_numeric($binding) ? $binding : "'" . addslashes($binding) . "'";
                $query   = preg_replace('/\?/', $binding, $query, 1);
            }
            return $query;
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
                        return "Mark as " . ($this->error->is_resolved ? 'unresolved' : 'resolved');
                    })
                    ->action(function () {
                        $this->error->is_resolved = !$this->error->is_resolved;
                        $this->error->save();
                    })
                    ->requiresConfirmation(),
                CopyAction::make()
                    ->copyable(function ( $record ) {
                        $publicLink = ErrorReportPublicLink::create([
                            'error_report_id' => $record->id,
                            'created_by'      => auth()->user()->id,
                            'token'           => Str::uuid()->toString()
                        ]);

                        return \route('share-error', [ 'error_token' => $publicLink->token ]);
                    })
                    ->label('Share')
                    ->icon('heroicon-o-arrow-uturn-right')

            ];
        }

    }
