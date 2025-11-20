<?php

use App\Enums\SeoIssueSeverity;

test('it has all expected cases', function () {
    $cases = SeoIssueSeverity::cases();

    expect($cases)->toHaveCount(3);
});

test('it contains all expected case instances', function () {
    $cases = SeoIssueSeverity::cases();

    expect($cases)->toContain(SeoIssueSeverity::Error);
    expect($cases)->toContain(SeoIssueSeverity::Warning);
    expect($cases)->toContain(SeoIssueSeverity::Notice);
});

test('it has correct values for all cases', function () {
    expect(SeoIssueSeverity::Error->value)->toBe('error');
    expect(SeoIssueSeverity::Warning->value)->toBe('warning');
    expect(SeoIssueSeverity::Notice->value)->toBe('notice');
});

test('it returns correct labels', function () {
    expect(SeoIssueSeverity::Error->getLabel())->toBe('Error');
    expect(SeoIssueSeverity::Warning->getLabel())->toBe('Warning');
    expect(SeoIssueSeverity::Notice->getLabel())->toBe('Notice');
});

test('it returns correct colors', function () {
    expect(SeoIssueSeverity::Error->getColor())->toBe('danger');
    expect(SeoIssueSeverity::Warning->getColor())->toBe('warning');
    expect(SeoIssueSeverity::Notice->getColor())->toBe('info');
});

test('it returns correct priorities', function () {
    expect(SeoIssueSeverity::Error->getPriority())->toBe(1);
    expect(SeoIssueSeverity::Warning->getPriority())->toBe(2);
    expect(SeoIssueSeverity::Notice->getPriority())->toBe(3);
});

test('error has highest priority', function () {
    $error = SeoIssueSeverity::Error->getPriority();
    $warning = SeoIssueSeverity::Warning->getPriority();
    $notice = SeoIssueSeverity::Notice->getPriority();

    expect($error < $warning)->toBeTrue();
    expect($warning < $notice)->toBeTrue();
});

test('it can be serialized to string', function () {
    expect((string) SeoIssueSeverity::Error->value)->toBe('error');
    expect((string) SeoIssueSeverity::Warning->value)->toBe('warning');
    expect((string) SeoIssueSeverity::Notice->value)->toBe('notice');
});

test('it can be instantiated from value', function () {
    expect(SeoIssueSeverity::from('error'))->toBe(SeoIssueSeverity::Error);
    expect(SeoIssueSeverity::from('warning'))->toBe(SeoIssueSeverity::Warning);
    expect(SeoIssueSeverity::from('notice'))->toBe(SeoIssueSeverity::Notice);
});

test('it returns null for invalid value with try from', function () {
    expect(SeoIssueSeverity::tryFrom('critical'))->toBeNull();
    expect(SeoIssueSeverity::tryFrom('info'))->toBeNull();
});

test('it can be compared with equality', function () {
    $error1 = SeoIssueSeverity::Error;
    $error2 = SeoIssueSeverity::Error;
    $warning = SeoIssueSeverity::Warning;

    expect($error1 === $error2)->toBeTrue();
    expect($error1 === $warning)->toBeFalse();
});

test('all severity levels have unique priorities', function () {
    $priorities = array_map(
        fn (SeoIssueSeverity $severity) => $severity->getPriority(),
        SeoIssueSeverity::cases()
    );

    expect(array_unique($priorities))->toHaveCount(count(SeoIssueSeverity::cases()));
});
