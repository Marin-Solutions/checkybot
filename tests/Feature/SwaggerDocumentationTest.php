<?php

test('swagger documentation can be generated', function () {
    $this->artisan('l5-swagger:generate')
        ->assertSuccessful();

    expect(storage_path('api-docs/api-docs.json'))->toBeFile();
});
