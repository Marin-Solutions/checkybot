<?php

use App\Mail\HealthStatusAlert;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('send test notification dispatches sample email when channel type is mail', function () {
    Mail::fake();

    $setting = NotificationSetting::factory()->email()->create([
        'address' => 'ops@example.com',
    ]);

    $result = $setting->sendTestNotification();

    expect($result)
        ->toBeArray()
        ->toHaveKeys(['ok', 'title', 'body']);
    expect($result['ok'])->toBeTrue();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail) {
        return $mail->hasTo('ops@example.com')
            && $mail->event === 'test_notification'
            && $mail->status === 'ok';
    });
});

test('send test notification reports failure when email address is missing', function () {
    Mail::fake();

    $setting = NotificationSetting::factory()->email()->create([
        'address' => null,
    ]);

    $result = $setting->sendTestNotification();

    expect($result['ok'])->toBeFalse();
    Mail::assertNothingSent();
});

test('send test notification triggers webhook with sample payload', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $channel = NotificationChannels::factory()->create([
        'title' => 'Pager',
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'request_body' => [
            'message' => '{message}',
            'description' => '{description}',
        ],
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'notification_channel_id' => $channel->id,
    ]);

    $result = $setting->sendTestNotification();

    expect($result['ok'])->toBeTrue();
    expect($result['title'])->toContain('delivered');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/webhook'
            && ! str_contains($body['message'] ?? '', '{message}')
            && ! str_contains($body['description'] ?? '', '{description}');
    });
});

test('send test notification reports failure with the real upstream status code', function () {
    Http::fake([
        '*' => Http::response(['error' => 'nope'], 502),
    ]);

    $channel = NotificationChannels::factory()->create([
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'notification_channel_id' => $channel->id,
    ]);

    $result = $setting->sendTestNotification();

    expect($result['ok'])->toBeFalse();
    expect($result['title'])->toContain('failed');
    expect($result['body'])->toContain('HTTP 502');
});

test('send test notification reports a 2xx response (e.g. 204) as success', function () {
    Http::fake([
        '*' => Http::response('', 204),
    ]);

    $channel = NotificationChannels::factory()->create([
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'notification_channel_id' => $channel->id,
    ]);

    $result = $setting->sendTestNotification();

    expect($result['ok'])->toBeTrue();
    expect($result['body'])->toContain('HTTP 204');
});

test('send test notification reports failure when webhook channel was deleted', function () {
    $setting = NotificationSetting::factory()->webhook()->create();
    $setting->channel()->delete();
    $setting->refresh();

    $result = $setting->sendTestNotification();

    expect($result['ok'])->toBeFalse();
    expect($result['body'])->toContain('not linked');
});

test('send test notification labels curl-style errnos as network errors, not HTTP statuses', function () {
    $channel = NotificationChannels::factory()->create([
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'notification_channel_id' => $channel->id,
    ]);

    /**
     * sendWebhookNotification() reuses the `code` key for curl errnos when a
     * RequestException is caught (e.g. errno 60 = SSL handshake). Simulate that
     * path by stubbing the channel relation with a model whose
     * sendWebhookNotification returns the errno-shaped response.
     */
    $stubChannel = new class extends NotificationChannels
    {
        public string $title = 'TLS-broken endpoint';

        public function sendWebhookNotification(array $data): array
        {
            return ['url' => 'https://example.com/webhook', 'code' => 60, 'body' => 'SSL certificate problem'];
        }
    };
    $stubChannel->title = 'TLS-broken endpoint';

    $setting->setRelation('channel', $stubChannel);

    $result = $setting->sendTestNotification();

    expect($result['ok'])->toBeFalse();
    expect($result['body'])
        ->toContain('curl errno 60')
        ->not->toContain('HTTP 60');
});
