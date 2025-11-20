<?php

use App\Models\NotificationChannels;
use Illuminate\Support\Facades\Http;

test('notification channel has fillable attributes', function () {
    $channel = NotificationChannels::factory()->create([
        'title' => 'Test Webhook',
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'description' => 'Test description',
    ]);

    expect($channel->title)->toBe('Test Webhook');
    expect($channel->method)->toBe('POST');
    expect($channel->url)->toBe('https://example.com/webhook');
    expect($channel->description)->toBe('Test description');
});

test('notification channel casts request body to array', function () {
    $channel = NotificationChannels::factory()->create([
        'request_body' => ['message' => '{message}'],
    ]);

    expect($channel->request_body)->toBeArray();
    expect($channel->request_body)->toBe(['message' => '{message}']);
});

test('notification channel can be slack', function () {
    $channel = NotificationChannels::factory()->slack()->create();

    expect($channel->title)->toBe('Slack Webhook');
    expect($channel->url)->toContain('slack.com');
    expect($channel->request_body)->toHaveKey('text');
});

test('notification channel can be discord', function () {
    $channel = NotificationChannels::factory()->discord()->create();

    expect($channel->title)->toBe('Discord Webhook');
    expect($channel->url)->toContain('discord.com');
    expect($channel->request_body)->toHaveKey('content');
});

test('test webhook sends get request', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

    $result = NotificationChannels::testWebhook([
        'method' => 'get',
        'url' => 'https://example.com/webhook',
        'description' => 'Test webhook',
    ]);

    expect($result['code'])->toBe(200);
    expect($result['body'])->toBe(['status' => 'ok']);
});

test('test webhook sends post request', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $result = NotificationChannels::testWebhook([
        'method' => 'post',
        'url' => 'https://example.com/webhook',
        'description' => 'Test webhook',
        'request_body' => ['message' => '{message}'],
    ]);

    expect($result['code'])->toBe(200);
    expect($result['body'])->toBe(['success' => true]);
});

test('test webhook replaces message placeholder in url', function () {
    Http::fake(['*' => Http::response([], 200)]);

    NotificationChannels::testWebhook([
        'method' => 'get',
        'url' => 'https://example.com/webhook?text={message}',
        'description' => 'Test',
    ]);

    Http::assertSent(function ($request) {
        return ! str_contains($request->url(), '{message}');
    });
});

test('test webhook replaces description placeholder in url', function () {
    Http::fake(['*' => Http::response([], 200)]);

    NotificationChannels::testWebhook([
        'method' => 'get',
        'url' => 'https://example.com/webhook?desc={description}',
        'description' => 'My Description',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'My%20Description');
    });
});

test('test webhook replaces placeholders in request body', function () {
    Http::fake(['*' => Http::response([], 200)]);

    NotificationChannels::testWebhook([
        'method' => 'post',
        'url' => 'https://example.com/webhook',
        'description' => 'Test Description',
        'request_body' => [
            'msg' => '{message}',
            'desc' => '{description}',
        ],
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ! str_contains($body['msg'] ?? '', '{message}')
            && ! str_contains($body['desc'] ?? '', '{description}');
    });
});

test('send webhook notification sends request', function () {
    Http::fake(['*' => Http::response(['result' => 'sent'], 200)]);

    $channel = NotificationChannels::factory()->create([
        'url' => 'https://example.com/webhook',
        'method' => 'POST',
        'request_body' => ['message' => '{message}'],
    ]);

    $result = $channel->sendWebhookNotification([
        'message' => 'Test notification',
        'description' => 'Test description',
    ]);

    expect($result['code'])->toBe(200);
    expect($result['body'])->toBe(['result' => 'sent']);
});

test('send webhook notification replaces placeholders', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $channel = NotificationChannels::factory()->create([
        'url' => 'https://example.com/webhook?text={message}',
        'method' => 'POST',
        'request_body' => ['description' => '{description}'],
    ]);

    $channel->sendWebhookNotification([
        'message' => 'Alert!',
        'description' => 'Server is down',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'Alert')
            && ! str_contains($request->url(), '{message}');
    });
});

test('send webhook notification handles errors', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $channel = NotificationChannels::factory()->create();

    $result = $channel->sendWebhookNotification([
        'message' => 'Test',
    ]);

    expect($result['code'])->toBe(0);
});
