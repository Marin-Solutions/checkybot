<?php

use App\Services\IntervalParser;

test('parses minutes correctly', function () {
    expect(IntervalParser::toMinutes('5m'))->toBe(5);
    expect(IntervalParser::toMinutes('1m'))->toBe(1);
    expect(IntervalParser::toMinutes('30m'))->toBe(30);
});

test('parses hours to minutes correctly', function () {
    expect(IntervalParser::toMinutes('1h'))->toBe(60);
    expect(IntervalParser::toMinutes('2h'))->toBe(120);
    expect(IntervalParser::toMinutes('3h'))->toBe(180);
});

test('parses days to minutes correctly', function () {
    expect(IntervalParser::toMinutes('1d'))->toBe(1440);
    expect(IntervalParser::toMinutes('2d'))->toBe(2880);
    expect(IntervalParser::toMinutes('3d'))->toBe(4320);
});

test('throws exception for invalid format', function () {
    IntervalParser::toMinutes('invalid');
})->throws(\InvalidArgumentException::class, 'Invalid interval format: invalid');

test('throws exception for missing unit', function () {
    IntervalParser::toMinutes('5');
})->throws(\InvalidArgumentException::class);

test('throws exception for missing number', function () {
    IntervalParser::toMinutes('m');
})->throws(\InvalidArgumentException::class);

test('throws exception for unknown unit', function () {
    IntervalParser::toMinutes('5x');
})->throws(\InvalidArgumentException::class);

test('validates correct interval formats', function () {
    expect(IntervalParser::isValid('5m'))->toBeTrue();
    expect(IntervalParser::isValid('2h'))->toBeTrue();
    expect(IntervalParser::isValid('1d'))->toBeTrue();
    expect(IntervalParser::isValid('30m'))->toBeTrue();
});

test('rejects invalid interval formats', function () {
    expect(IntervalParser::isValid('5'))->toBeFalse();
    expect(IntervalParser::isValid('m'))->toBeFalse();
    expect(IntervalParser::isValid('invalid'))->toBeFalse();
    expect(IntervalParser::isValid('5x'))->toBeFalse();
    expect(IntervalParser::isValid(''))->toBeFalse();
});

test('converts minutes back to interval string', function () {
    expect(IntervalParser::fromMinutes(5))->toBe('5m');
    expect(IntervalParser::fromMinutes(60))->toBe('1h');
    expect(IntervalParser::fromMinutes(120))->toBe('2h');
    expect(IntervalParser::fromMinutes(1440))->toBe('1d');
    expect(IntervalParser::fromMinutes(2880))->toBe('2d');
});

test('prefers hours over minutes when converting', function () {
    expect(IntervalParser::fromMinutes(60))->toBe('1h');
    expect(IntervalParser::fromMinutes(120))->toBe('2h');
});

test('prefers days over hours when converting', function () {
    expect(IntervalParser::fromMinutes(1440))->toBe('1d');
    expect(IntervalParser::fromMinutes(2880))->toBe('2d');
});

test('uses minutes when not evenly divisible by hours', function () {
    expect(IntervalParser::fromMinutes(65))->toBe('65m');
    expect(IntervalParser::fromMinutes(90))->toBe('90m');
    expect(IntervalParser::fromMinutes(1500))->toBe('25h');
});
