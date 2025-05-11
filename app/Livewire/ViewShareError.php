<?php

    namespace App\Livewire;

    use App\Models\ErrorReportPublicLink;
    use Filament\Forms\Concerns\InteractsWithForms;
    use Filament\Forms\Contracts\HasForms;
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
    use Filament\Pages\BasePage;
    use Illuminate\Database\Eloquent\Model;
    use Livewire\Attributes\Locked;

    class ViewShareError extends BasePage implements HasForms, HasInfolists
    {
        use InteractsWithInfolists;
        use InteractsWithForms;

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

            $error      = $this->errorToken->errorReport;
            $error->request_headers  = $error->context[ 'headers' ];
            $error->request_body     = $error->context[ 'request_data' ][ 'body' ];
            $error->route_name       = $error->context[ 'route' ][ 'route' ];
            $error->route_action     = $error->context[ 'route' ][ 'controllerAction' ];
            $error->route_middleware = $error->context[ 'route' ][ 'middleware' ];
            $error->route_parameters = $error->context[ 'route' ][ 'routeParameters' ];
            $error->queries          = $error->context[ 'queries' ];

            $this->error = $error;
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

    }
