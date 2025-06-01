<?php

    namespace App\Traits;

    use Filament\Notifications\Notification;

    trait HandlesPloiVerificationNotification
    {
        public static function notifyPloiVerificationResult(array $result): void
        {
            if ( !$result[ 'is_verified' ] ) {
                Notification::make()
                    ->title('Verification Failed')
                    ->body($result[ 'error_message' ])
                    ->danger()
                    ->send()
                ;
            } else {
                Notification::make()
                    ->title('Verification Successful')
                    ->body('API key is valid.')
                    ->success()
                    ->send()
                ;
            }
        }
    }
