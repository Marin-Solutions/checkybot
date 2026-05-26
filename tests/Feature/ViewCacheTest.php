<?php

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(config('view.compiled'));
});

test('production view cache compiles all Blade templates', function () {
    config(['view.compiled' => storage_path('framework/testing/views-cache-'.getmypid())]);

    $this->artisan('view:cache')->assertSuccessful();
});
