<?php

use App\Enums\NotificationScopesEnum;

test('it has all expected cases', function () {
    $cases = NotificationScopesEnum::cases();

    expect($cases)->toHaveCount(3);
});

test('it contains all expected case instances', function () {
    $cases = NotificationScopesEnum::cases();

    expect($cases)->toContain(NotificationScopesEnum::GLOBAL);
    expect($cases)->toContain(NotificationScopesEnum::WEBSITE);
    expect($cases)->toContain(NotificationScopesEnum::API_MONITOR);
});

test('it has correct values for all cases', function () {
    expect(NotificationScopesEnum::GLOBAL->value)->toBe('GLOBAL');
    expect(NotificationScopesEnum::WEBSITE->value)->toBe('WEBSITE');
    expect(NotificationScopesEnum::API_MONITOR->value)->toBe('API_MONITOR');
});

test('it returns correct labels', function () {
    expect(NotificationScopesEnum::GLOBAL->label())->toBe('Global');
    expect(NotificationScopesEnum::WEBSITE->label())->toBe('Website');
    expect(NotificationScopesEnum::API_MONITOR->label())->toBe('API Monitor');
});

test('keys method returns array of case names', function () {
    $keys = NotificationScopesEnum::keys();

    expect($keys)->toBeArray();
    expect($keys)->toHaveCount(3);
    expect($keys)->toContain('GLOBAL');
    expect($keys)->toContain('WEBSITE');
    expect($keys)->toContain('API_MONITOR');
});

test('to array returns associative array of values and labels', function () {
    $array = NotificationScopesEnum::toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(3);
    expect($array)->toBe([
        'GLOBAL' => 'Global',
        'WEBSITE' => 'Website',
        'API_MONITOR' => 'API Monitor',
    ]);
});

test('to array keys match enum values', function () {
    $array = NotificationScopesEnum::toArray();
    $keys = array_keys($array);

    foreach (NotificationScopesEnum::cases() as $case) {
        expect($keys)->toContain($case->value);
    }
});

test('to array values match labels', function () {
    $array = NotificationScopesEnum::toArray();

    foreach (NotificationScopesEnum::cases() as $case) {
        expect($array[$case->value])->toBe($case->label());
    }
});

test('it can be serialized to string', function () {
    expect((string) NotificationScopesEnum::GLOBAL->value)->toBe('GLOBAL');
    expect((string) NotificationScopesEnum::WEBSITE->value)->toBe('WEBSITE');
    expect((string) NotificationScopesEnum::API_MONITOR->value)->toBe('API_MONITOR');
});

test('it can be instantiated from value', function () {
    expect(NotificationScopesEnum::from('GLOBAL'))->toBe(NotificationScopesEnum::GLOBAL);
    expect(NotificationScopesEnum::from('WEBSITE'))->toBe(NotificationScopesEnum::WEBSITE);
    expect(NotificationScopesEnum::from('API_MONITOR'))->toBe(NotificationScopesEnum::API_MONITOR);
});

test('it returns null for invalid value with try from', function () {
    expect(NotificationScopesEnum::tryFrom('LOCAL'))->toBeNull();
    expect(NotificationScopesEnum::tryFrom('USER'))->toBeNull();
});

test('it can be compared with equality', function () {
    $global1 = NotificationScopesEnum::GLOBAL;
    $global2 = NotificationScopesEnum::GLOBAL;
    $website = NotificationScopesEnum::WEBSITE;

    expect($global1 === $global2)->toBeTrue();
    expect($global1 === $website)->toBeFalse();
});
