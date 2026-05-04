<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
    ...(
        env('TELESCOPE_ENABLED', env('APP_ENV') === 'local')
            ? [App\Providers\TelescopeServiceProvider::class]
            : []
    ),
];
