<?php

use App\Enums\WebsiteServicesEnum;

test('it has all expected cases', function () {
    $cases = WebsiteServicesEnum::cases();

    expect($cases)->toHaveCount(3);
});

test('it contains all expected case instances', function () {
    $cases = WebsiteServicesEnum::cases();

    expect($cases)->toContain(WebsiteServicesEnum::WEBSITE_CHECK);
    expect($cases)->toContain(WebsiteServicesEnum::API_MONITOR);
    expect($cases)->toContain(WebsiteServicesEnum::ALL_CHECK);
});

test('it has correct values for all cases', function () {
    expect(WebsiteServicesEnum::WEBSITE_CHECK->value)->toBe('WEBSITE_CHECK');
    expect(WebsiteServicesEnum::API_MONITOR->value)->toBe('API_MONITOR');
    expect(WebsiteServicesEnum::ALL_CHECK->value)->toBe('ALL_CHECK');
});

test('it returns correct labels', function () {
    expect(WebsiteServicesEnum::WEBSITE_CHECK->label())->toBe('Website Check');
    expect(WebsiteServicesEnum::API_MONITOR->label())->toBe('API Monitor');
    expect(WebsiteServicesEnum::ALL_CHECK->label())->toBe('All Check');
});

test('keys method returns array of case names', function () {
    $keys = WebsiteServicesEnum::keys();

    expect($keys)->toBeArray();
    expect($keys)->toHaveCount(3);
    expect($keys)->toContain('WEBSITE_CHECK');
    expect($keys)->toContain('API_MONITOR');
    expect($keys)->toContain('ALL_CHECK');
});

test('to array returns associative array of values and labels', function () {
    $array = WebsiteServicesEnum::toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(3);
    expect($array)->toBe([
        'WEBSITE_CHECK' => 'Website Check',
        'API_MONITOR' => 'API Monitor',
        'ALL_CHECK' => 'All Check',
    ]);
});

test('to array keys match enum values', function () {
    $array = WebsiteServicesEnum::toArray();
    $keys = array_keys($array);

    foreach (WebsiteServicesEnum::cases() as $case) {
        expect($keys)->toContain($case->value);
    }
});

test('to array values match labels', function () {
    $array = WebsiteServicesEnum::toArray();

    foreach (WebsiteServicesEnum::cases() as $case) {
        expect($array[$case->value])->toBe($case->label());
    }
});

test('it can be serialized to string', function () {
    expect((string) WebsiteServicesEnum::WEBSITE_CHECK->value)->toBe('WEBSITE_CHECK');
    expect((string) WebsiteServicesEnum::API_MONITOR->value)->toBe('API_MONITOR');
    expect((string) WebsiteServicesEnum::ALL_CHECK->value)->toBe('ALL_CHECK');
});

test('it can be instantiated from value', function () {
    expect(WebsiteServicesEnum::from('WEBSITE_CHECK'))->toBe(WebsiteServicesEnum::WEBSITE_CHECK);
    expect(WebsiteServicesEnum::from('API_MONITOR'))->toBe(WebsiteServicesEnum::API_MONITOR);
    expect(WebsiteServicesEnum::from('ALL_CHECK'))->toBe(WebsiteServicesEnum::ALL_CHECK);
});

test('it returns null for invalid value with try from', function () {
    expect(WebsiteServicesEnum::tryFrom('INVALID_CHECK'))->toBeNull();
    expect(WebsiteServicesEnum::tryFrom('SERVER_MONITOR'))->toBeNull();
});

test('it can be compared with equality', function () {
    $websiteCheck1 = WebsiteServicesEnum::WEBSITE_CHECK;
    $websiteCheck2 = WebsiteServicesEnum::WEBSITE_CHECK;
    $apiMonitor = WebsiteServicesEnum::API_MONITOR;

    expect($websiteCheck1 === $websiteCheck2)->toBeTrue();
    expect($websiteCheck1 === $apiMonitor)->toBeFalse();
});
