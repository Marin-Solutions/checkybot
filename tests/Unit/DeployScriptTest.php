<?php

test('ploi deploy warms all Laravel caches before bringing the app up', function () {
    $deployScript = file(base_path('scripts/deploy/ploi.sh'), FILE_IGNORE_NEW_LINES);

    $commands = array_values(array_filter($deployScript, fn (string $line): bool => str_starts_with($line, 'php artisan ')
        || $line === 'bring_application_up'));

    $cacheWarmupStart = array_search('php artisan optimize:clear', $commands, true);
    $bringApplicationUp = array_search('bring_application_up', $commands, true);

    expect($cacheWarmupStart)->not->toBeFalse()
        ->and($bringApplicationUp)->not->toBeFalse()
        ->and(array_slice($commands, $cacheWarmupStart, $bringApplicationUp - $cacheWarmupStart + 1))->toBe([
            'php artisan optimize:clear',
            'php artisan config:cache',
            'php artisan route:cache',
            'php artisan view:cache',
            'php artisan event:cache',
            'bring_application_up',
        ]);
});
