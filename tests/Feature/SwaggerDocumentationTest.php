<?php

test('swagger documentation can be generated', function () {
    $this->artisan('l5-swagger:generate')
        ->assertSuccessful();

    $documentation = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
    $requestSchema = fn (string $path, string $method = 'post'): array => $documentation['paths'][$path][$method]['requestBody']['content']['application/json']['schema'];

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
        ->and($documentation['paths']['/v1/projects/{project}/checks/sync']['post']['responses'])->toHaveKeys(['200', '401', '403', '404', '422'])
        ->and($documentation['paths']['/v1/projects/{project}/components/sync']['post']['responses'])->toHaveKeys(['200', '401', '403', '404', '422'])
        ->and($documentation['paths']['/v1/control/projects/{project}/checks/{check}/runs']['post']['responses'])->toHaveKeys(['200', '401', '404', '409'])
        ->and($documentation['paths']['/v1/control/runs']['get']['responses'])->toHaveKeys(['200', '401', '404', '422'])
        ->and($documentation['paths']['/v1/control/failures']['get']['responses'])->toHaveKeys(['200', '401', '404', '422'])
        ->and($documentation['paths']['/v1/mcp']['post']['requestBody']['content']['application/json']['schema']['properties']['id']['oneOf'])->sequence(
            fn ($schema) => $schema->type->toBe('string'),
            fn ($schema) => $schema->type->toBe('integer'),
            fn ($schema) => $schema->type->toBe('number'),
        )
        ->and($requestSchema('/v1/package/register')['required'])->toBe(['name', 'environment', 'identity_endpoint'])
        ->and($requestSchema('/v1/package/sync')['required'])->toBe(['project', 'checks'])
        ->and($requestSchema('/v1/package/sync')['properties']['project']['required'])->toBe(['key', 'name', 'environment', 'base_url'])
        ->and($requestSchema('/v1/package/sync')['properties']['checks']['items']['required'])->toBe(['key', 'type', 'name', 'method', 'url'])
        ->and($requestSchema('/v1/package/sync')['properties']['checks']['items']['properties']['method'])->not->toHaveKey('nullable')
        ->and($requestSchema('/v1/projects/{project}/checks/sync')['properties']['uptime_checks']['items']['required'])->toBe(['name', 'url', 'interval'])
        ->and($requestSchema('/v1/projects/{project}/checks/sync')['properties']['api_checks']['items']['properties']['assertions']['items']['required'])->toBe(['data_path', 'assertion_type'])
        ->and($requestSchema('/v1/projects/{project}/checks/sync')['properties']['api_checks']['items']['properties']['headers'])->not->toHaveKey('nullable')
        ->and($requestSchema('/v1/projects/{project}/checks/sync')['properties']['api_checks']['items']['properties']['assertions'])->not->toHaveKey('nullable')
        ->and($requestSchema('/v1/projects/{project}/checks/sync')['properties']['api_checks']['items']['properties']['assertions']['items']['properties']['sort_order'])->not->toHaveKey('nullable')
        ->and($requestSchema('/v1/projects/{project}/checks/sync')['properties']['api_checks']['items']['properties']['assertions']['items']['properties']['is_active'])->not->toHaveKey('nullable')
        ->and($requestSchema('/v1/projects/{project}/components/sync')['required'])->toBe(['declared_components', 'components'])
        ->and($requestSchema('/v1/projects/{project}/components/sync')['properties']['components']['items']['required'])->toBe(['name', 'interval', 'status', 'observed_at'])
        ->and($requestSchema('/v1/control/projects/{project}/checks/{check}', 'put')['required'])->toBe(['name', 'url'])
        ->and($requestSchema('/v1/control/projects/{project}/checks/{check}', 'put')['properties']['assertions']['items']['required'])->toBe(['type', 'path'])
        ->and($documentation['paths'])->toHaveCount(19);
});
