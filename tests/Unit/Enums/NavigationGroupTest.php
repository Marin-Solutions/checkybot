<?php

use App\Enums\NavigationGroup;

test('it has all expected cases', function () {
    $cases = NavigationGroup::cases();

    expect($cases)->toHaveCount(9);
});

test('it contains all expected case instances', function () {
    $cases = NavigationGroup::cases();

    expect($cases)->toContain(NavigationGroup::Operations);
    expect($cases)->toContain(NavigationGroup::SEO);
    expect($cases)->toContain(NavigationGroup::Settings);
    expect($cases)->toContain(NavigationGroup::Monitoring);
    expect($cases)->toContain(NavigationGroup::Backup);
    expect($cases)->toContain(NavigationGroup::Notifications);
    expect($cases)->toContain(NavigationGroup::API);
    expect($cases)->toContain(NavigationGroup::Server);
    expect($cases)->toContain(NavigationGroup::BackupManager);
});

test('it has correct values for all cases', function () {
    expect(NavigationGroup::Operations->value)->toBe('Operations');
    expect(NavigationGroup::SEO->value)->toBe('SEO');
    expect(NavigationGroup::Settings->value)->toBe('Settings');
    expect(NavigationGroup::Monitoring->value)->toBe('Monitoring');
    expect(NavigationGroup::Backup->value)->toBe('Backup');
    expect(NavigationGroup::Notifications->value)->toBe('Notifications');
    expect(NavigationGroup::API->value)->toBe('API');
    expect(NavigationGroup::Server->value)->toBe('Server');
    expect(NavigationGroup::BackupManager->value)->toBe('Backup Manager');
});

test('it can be serialized to string', function () {
    expect((string) NavigationGroup::Operations->value)->toBe('Operations');
    expect((string) NavigationGroup::SEO->value)->toBe('SEO');
    expect((string) NavigationGroup::Monitoring->value)->toBe('Monitoring');
});

test('it can be instantiated from value', function () {
    expect(NavigationGroup::from('Operations'))->toBe(NavigationGroup::Operations);
    expect(NavigationGroup::from('SEO'))->toBe(NavigationGroup::SEO);
    expect(NavigationGroup::from('Settings'))->toBe(NavigationGroup::Settings);
    expect(NavigationGroup::from('Monitoring'))->toBe(NavigationGroup::Monitoring);
    expect(NavigationGroup::from('Backup'))->toBe(NavigationGroup::Backup);
    expect(NavigationGroup::from('Notifications'))->toBe(NavigationGroup::Notifications);
    expect(NavigationGroup::from('API'))->toBe(NavigationGroup::API);
    expect(NavigationGroup::from('Server'))->toBe(NavigationGroup::Server);
    expect(NavigationGroup::from('Backup Manager'))->toBe(NavigationGroup::BackupManager);
});

test('it returns null for invalid value with try from', function () {
    expect(NavigationGroup::tryFrom('InvalidGroup'))->toBeNull();
});

test('it can be compared with equality', function () {
    $operations1 = NavigationGroup::Operations;
    $operations2 = NavigationGroup::Operations;
    $seo = NavigationGroup::SEO;

    expect($operations1 === $operations2)->toBeTrue();
    expect($operations1 === $seo)->toBeFalse();
});
