<?php

use App\Enums\NotificationChannelTypesEnum;

test('it has all expected cases', function () {
    $cases = NotificationChannelTypesEnum::cases();

    expect($cases)->toHaveCount(2);
});

test('it contains all expected case instances', function () {
    $cases = NotificationChannelTypesEnum::cases();

    expect($cases)->toContain(NotificationChannelTypesEnum::MAIL);
    expect($cases)->toContain(NotificationChannelTypesEnum::WEBHOOK);
});

test('it has correct values for all cases', function () {
    expect(NotificationChannelTypesEnum::MAIL->value)->toBe('MAIL');
    expect(NotificationChannelTypesEnum::WEBHOOK->value)->toBe('WEBHOOK');
});

test('it returns correct labels', function () {
    expect(NotificationChannelTypesEnum::MAIL->label())->toBe('Email');
    expect(NotificationChannelTypesEnum::WEBHOOK->label())->toBe('Webhook');
});

test('label method uses match expression', function () {
    expect(NotificationChannelTypesEnum::MAIL->label())->not->toBe('Mail');
    expect(NotificationChannelTypesEnum::MAIL->label())->toBe('Email');
});

test('keys method returns array of case names', function () {
    $keys = NotificationChannelTypesEnum::keys();

    expect($keys)->toBeArray();
    expect($keys)->toHaveCount(2);
    expect($keys)->toContain('MAIL');
    expect($keys)->toContain('WEBHOOK');
});

test('to array returns associative array of values and labels', function () {
    $array = NotificationChannelTypesEnum::toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(2);
    expect($array)->toBe([
        'MAIL' => 'Email',
        'WEBHOOK' => 'Webhook',
    ]);
});

test('to array keys match enum values', function () {
    $array = NotificationChannelTypesEnum::toArray();
    $keys = array_keys($array);

    foreach (NotificationChannelTypesEnum::cases() as $case) {
        expect($keys)->toContain($case->value);
    }
});

test('to array values match labels', function () {
    $array = NotificationChannelTypesEnum::toArray();

    foreach (NotificationChannelTypesEnum::cases() as $case) {
        expect($array[$case->value])->toBe($case->label());
    }
});

test('it can be serialized to string', function () {
    expect((string) NotificationChannelTypesEnum::MAIL->value)->toBe('MAIL');
    expect((string) NotificationChannelTypesEnum::WEBHOOK->value)->toBe('WEBHOOK');
});

test('it can be instantiated from value', function () {
    expect(NotificationChannelTypesEnum::from('MAIL'))->toBe(NotificationChannelTypesEnum::MAIL);
    expect(NotificationChannelTypesEnum::from('WEBHOOK'))->toBe(NotificationChannelTypesEnum::WEBHOOK);
});

test('it returns null for invalid value with try from', function () {
    expect(NotificationChannelTypesEnum::tryFrom('SMS'))->toBeNull();
    expect(NotificationChannelTypesEnum::tryFrom('SLACK'))->toBeNull();
    expect(NotificationChannelTypesEnum::tryFrom('PUSH'))->toBeNull();
});

test('it can be compared with equality', function () {
    $mail1 = NotificationChannelTypesEnum::MAIL;
    $mail2 = NotificationChannelTypesEnum::MAIL;
    $webhook = NotificationChannelTypesEnum::WEBHOOK;

    expect($mail1 === $mail2)->toBeTrue();
    expect($mail1 === $webhook)->toBeFalse();
});
