<?php

    namespace App\Traits;

    use App\Models\ErrorReports;
    use Filament\Infolists\Components\Fieldset;
    use Filament\Infolists\Components\KeyValueEntry;
    use Filament\Infolists\Components\RepeatableEntry;
    use Filament\Infolists\Components\Tabs;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Infolist;

    trait ErsHasErrorRequestInfolist
    {
        public function shouldShowRequestInfolist(): bool
        {
            $error = $this->error;
            return !(
                empty($error->request_method) &&
                empty($error->request_headers) &&
                empty($error->request_body) &&
                empty($error->route_action) &&
                empty($error->route_name) &&
                empty($error->route_middleware) &&
                empty($error->route_parameters) &&
                empty($error->queries)
            );
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
                                    TextEntry::make('request_method')
                                        ->label('Method')
                                        ->badge()
                                        ->color('gray')
                                        ->hidden(fn( $state ) => empty($state)),
                                    KeyValueEntry::make('request_headers')->hidden(fn( $state ) => empty($state)),
                                    KeyValueEntry::make('request_body')->hidden(fn( $state ) => empty($state))
                                ])
                                ->hidden(function ( $record ) {
                                    return empty($record->request_method) &&
                                        empty($record->request_headers) &&
                                        empty($record->request_body);
                                }),
                            Tabs\Tab::make('Application')
                                ->schema([
                                    Fieldset::make('Routing')
                                        ->schema([
                                            TextEntry::make('route_action')
                                                ->label('Controller')
                                                ->html()
                                                ->formatStateUsing(function ( $state ) {
                                                    return "<pre class='text-sm'>" . e($state) . "</pre>";
                                                })
                                                ->hidden(fn( $state ) => empty($state)),
                                            TextEntry::make('route_name')
                                                ->label('Route name')
                                                ->hidden(fn( $state ) => empty($state)),
                                            TextEntry::make('route_middleware')
                                                ->label('Middleware')
                                                ->formatStateUsing(fn( ErrorReports $record ) => implode(', ', $record->getRouteMiddlewareAttribute()))
                                                ->hidden(fn( $state ) => empty($state)),
                                            KeyValueEntry::make('route_parameters')
                                                ->label('Parameters')
                                                ->hidden(fn( $state ) => empty($state))
                                        ])
                                        ->hidden(fn( $record ) => empty($record->route_action) &&
                                            empty($record->route_name) &&
                                            empty($record->route_middleware) &&
                                            empty($record->route_parameters)
                                        )
                                        ->columns(1),
                                    RepeatableEntry::make('queries')
                                        ->label("Database Queries")
                                        ->schema([
                                            TextEntry::make('connection_name'),
                                            TextEntry::make('time')
                                                ->formatStateUsing(fn( string $state ) => $state . ' ms'),
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
                                        ->hidden(fn( $state ) => empty($state))
                                ])
                                ->hidden(fn( $record ) => ( empty($record->route_action) &&
                                        empty($record->route_name) &&
                                        empty($record->route_middleware) &&
                                        empty($record->route_parameters) ) &&
                                    empty($record->queries)
                                ),
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
