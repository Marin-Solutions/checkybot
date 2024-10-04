<?php

    namespace App\Traits;

    use Filament\Actions\Action;
    use Filament\Notifications\Notification;
    use Illuminate\Support\Arr;

    trait MonitoringApis
    {
        public function callDoMonitoring( $form ): void
        {
            $validatedData = $form->getState();

            if ( $form->validate() ) {
                $callback = \App\Models\MonitorApis::testApi($validatedData);

                if ( $callback[ 'code' ] != 200 ) {
                    $responseFail = 'danger';
                    if ( $callback[ 'code' ] == 60 ) {
                        $title = 'URL website, problem with certificate';
                        $body  = $callback[ 'body' ];
                    } else if ( $callback[ 'body' ] == 1 ) {
                        $title = 'URL Website Response error';
                        $body  = 'The website response is not 200!';
                    } else {
                        $title = 'URL website a unknown error. try other url';
                        $body  = $callback[ 'body' ] . ' code errno:' . $callback[ 'code' ];
                    }
                } else {
                    $responseFail = 'success';
                    $title        = "API response is as expected";
                    $body         = '"' . $validatedData[ 'data_path' ] . '" is contained in the response and the value is "' . Arr::get($callback[ 'body' ], $validatedData[ 'data_path' ]) . '"';

                    if ( !Arr::has($callback[ 'body' ], $validatedData[ 'data_path' ]) ) {
                        $responseFail = 'warning';
                        $title        = "API response is not as expected";
                        $body         = '"' . $validatedData[ 'data_path' ] . '" is not contained in the response';
                    }
                }

                Notification::make()
                    ->{$responseFail}()
                    ->title(__($title))
                    ->body(__($body))
                    ->send()
                ;

//                if ( $responseFail ) {
//                    Notification::make()
//                        ->danger()
//                        ->title(__($title))
//                        ->body(__($body))
//                        ->send()
//                    ;
//                } else {
//                    Notification::make()
//                        ->success()
//                        ->title(__($title))
//                        ->body(__($body))
//                        ->send()
//                    ;
//                }
            }
        }

        public function doMonitorApiAction(): Action
        {
            return Action::make('check_api')
                ->label('Check API')
                ->color('warning')
                ->button()
                ->outlined()
                ->action('doMonitoring')
            ;
        }
    }
