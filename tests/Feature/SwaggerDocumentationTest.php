<?php

test('swagger documentation can be generated', function () {
    $this->artisan('l5-swagger:generate')
        ->assertSuccessful();

    $documentation = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(storage_path('api-docs/api-docs.json'))->toBeFile()
        ->and(array_keys($documentation['paths']))->toContain(
            '/v1/control/me',
            '/v1/control/projects',
            '/v1/control/projects/{project}',
            '/v1/control/projects/{project}/checks',
            '/v1/control/projects/{project}/checks/{check}',
            '/v1/control/projects/{project}/runs',
            '/v1/control/failures',
            '/v1/mcp',
            '/v1/package/register',
            '/v1/package/sync',
        )
        ->and($documentation['components']['securitySchemes'])->toHaveKey('checkybotApiKey')
        ->and($documentation['paths']['/v1/package/register']['post']['responses'])->toHaveKeys(['200', '201', '401', '422'])
        ->and($documentation['paths']['/v1/package/sync']['post']['responses'])->toHaveKeys(['200', '201', '401', '422'])
        ->and($documentation['paths']['/v1/control/projects/{project}/checks/{check}/runs']['post']['responses'])->toHaveKeys(['200', '401', '404', '409'])
        ->and($documentation['paths']['/v1/control/runs']['get']['responses'])->toHaveKeys(['200', '401', '404', '422'])
        ->and($documentation['paths']['/v1/control/failures']['get']['responses'])->toHaveKeys(['200', '401', '404', '422'])
        ->and($documentation['paths'])->toHaveCount(19);
});
