<?php

use App\Enums\WebhookHttpMethod;

test('it has all expected cases', function () {
    $cases = WebhookHttpMethod::cases();

    expect($cases)->toHaveCount(2);
});

test('it contains all expected case instances', function () {
    $cases = WebhookHttpMethod::cases();

    expect($cases)->toContain(WebhookHttpMethod::GET);
    expect($cases)->toContain(WebhookHttpMethod::POST);
});

test('it has correct values for all cases', function () {
    expect(WebhookHttpMethod::GET->value)->toBe('GET');
    expect(WebhookHttpMethod::POST->value)->toBe('POST');
});

test('all values are uppercase', function () {
    foreach (WebhookHttpMethod::cases() as $method) {
        expect($method->value)->toBe(strtoupper($method->value));
    }
});

test('it can be serialized to string', function () {
    expect((string) WebhookHttpMethod::GET->value)->toBe('GET');
    expect((string) WebhookHttpMethod::POST->value)->toBe('POST');
});

test('it can be instantiated from value', function () {
    expect(WebhookHttpMethod::from('GET'))->toBe(WebhookHttpMethod::GET);
    expect(WebhookHttpMethod::from('POST'))->toBe(WebhookHttpMethod::POST);
});

test('it returns null for invalid value with try from', function () {
    expect(WebhookHttpMethod::tryFrom('PUT'))->toBeNull();
    expect(WebhookHttpMethod::tryFrom('DELETE'))->toBeNull();
    expect(WebhookHttpMethod::tryFrom('PATCH'))->toBeNull();
});

test('it is case sensitive', function () {
    expect(WebhookHttpMethod::tryFrom('get'))->toBeNull();
    expect(WebhookHttpMethod::tryFrom('post'))->toBeNull();
    expect(WebhookHttpMethod::tryFrom('Get'))->toBeNull();
    expect(WebhookHttpMethod::tryFrom('Post'))->toBeNull();
});

test('it can be compared with equality', function () {
    $get1 = WebhookHttpMethod::GET;
    $get2 = WebhookHttpMethod::GET;
    $post = WebhookHttpMethod::POST;

    expect($get1 === $get2)->toBeTrue();
    expect($get1 === $post)->toBeFalse();
});
