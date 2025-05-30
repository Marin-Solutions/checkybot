<?php

    namespace App\Services;

    use App\Models\Website;
    use Filament\Notifications\Notification;

    class WebsiteUrlValidator
    {
        public static function validate( string $url, callable $halt ): void
        {
            $urlExistsInDB   = Website::whereUrl($url)->count();
            $urlCheckExists  = Website::checkWebsiteExists($url);
            $urlResponseCode = Website::checkResponseCode($url);
            $responseStatus  = false;

            if ( $urlResponseCode[ 'code' ] != 200 ) {
                $responseStatus = true;
                if ( $urlResponseCode[ 'code' ] == 60 ) {
                    $title = 'URL website, problem with certificate';
                    $body  = $urlResponseCode[ 'body' ];
                } else if ( $urlResponseCode[ 'body' ] == 1 ) {
                    $title = 'URL Website Response error';
                    $body  = 'The website response is not 200!';
                } else {
                    $title = 'URL website a unknown error. try other url';
                    $body  = $urlResponseCode[ 'body' ] . ' code errno:' . $urlResponseCode[ 'code' ];
                }
            }

            if ( $responseStatus ) {
                Notification::make()
                    ->danger()
                    ->title(__($title))
                    ->body(__($body))
                    ->send()
                ;
                $halt();
            }

            if ( $urlExistsInDB > 0 ) {
                Notification::make()
                    ->danger()
                    ->title(__('URL Website Exists in database'))
                    ->body(__('The new website exists in database, try again'))
                    ->send()
                ;
            }

            if ( !$urlCheckExists ) {
                Notification::make()
                    ->danger()
                    ->title(__('website was not registered'))
                    ->body(__('The new website not exists in DNS Lookup'))
                    ->send()
                ;
            }

            if ( $urlExistsInDB > 0 || !$urlCheckExists ) {
                $halt();
            }
        }
    }
