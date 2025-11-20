<?php

use App\Enums\NotificationScopesEnum;

test('it has all expected cases', function () {
    $cases = NotificationScopesEnum::cases();

    expect($cases)->toHaveCount(2);
});

test('it contains all expected case instances', function () {
    $cases = NotificationScopesEnum::cases();

    expect($cases)->toContain(NotificationScopesEnum::GLOBAL);
    expect($cases)->toContain(NotificationScopesEnum::WEBSITE);
});

test('it has correct values for all cases', function () {
    expect(NotificationScopesEnum::GLOBAL->value)->toBe('GLOBAL');
    expect(NotificationScopesEnum::WEBSITE->value)->toBe('WEBSITE');
});

test('it returns correct labels', function () {
    expect(NotificationScopesEnum::GLOBAL->label())->toBe('Global');
    expect(NotificationScopesEnum::WEBSITE->label())->toBe('Website');
});

test('label method returns capitalized lowercase value', function () {
    foreach (NotificationScopesEnum::cases() as $case) {
        $expectedLabel = ucfirst(strtolower($case->value));
        expect($case->label())->toBe($expectedLabel);
    }
});

test('keys method returns array of case names', function () {
    $keys = NotificationScopesEnum::keys();

    expect($keys)->toBeArray();
    expect($keys)->toHaveCount(2);
    expect($keys)->toContain('GLOBAL');
    expect($keys)->toContain('WEBSITE');
});

test('to array returns associative array of values and labels', function () {
    $array = NotificationScopesEnum::toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(2);
    expect($array)->toBe([
        'GLOBAL' => 'Global',
        'WEBSITE' => 'Website',
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
});

test('it can be instantiated from value', function () {
    expect(NotificationScopesEnum::from('GLOBAL'))->toBe(NotificationScopesEnum::GLOBAL);
    expect(NotificationScopesEnum::from('WEBSITE'))->toBe(NotificationScopesEnum::WEBSITE);
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
