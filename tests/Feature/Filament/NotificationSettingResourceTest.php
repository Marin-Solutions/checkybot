<?php

use App\Filament\Resources\NotificationSettingResource\Pages\ListNotificationSettings;
use App\Mail\HealthStatusAlert;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

test('send test action delivers sample email for mail notification setting', function () {
    Mail::fake();

    $user = $this->actingAsSuperAdmin();

    $setting = NotificationSetting::factory()->email()->create([
        'user_id' => $user->id,
        'address' => 'on-call@example.com',
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('sendTest', $setting)
        ->assertNotified('Test email sent');

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail) {
        return $mail->hasTo('on-call@example.com');
    });
});

test('send test action surfaces failure when email address is missing', function () {
    Mail::fake();

    $user = $this->actingAsSuperAdmin();

    $setting = NotificationSetting::factory()->email()->create([
        'user_id' => $user->id,
        'address' => null,
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('sendTest', $setting)
        ->assertNotified('Test email not sent');

    Mail::assertNothingSent();
});

test('send test action sends sample webhook payload through linked channel', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $user = $this->actingAsSuperAdmin();

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'title' => 'Pager Duty',
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'request_body' => [
            'message' => '{message}',
            'description' => '{description}',
        ],
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'user_id' => $user->id,
        'notification_channel_id' => $channel->id,
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('sendTest', $setting)
        ->assertNotified('Test webhook delivered');

    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/webhook');
});

test('send test action surfaces webhook failures with status code', function () {
    Http::fake([
        '*' => Http::response(['error' => 'down'], 502),
    ]);

    $user = $this->actingAsSuperAdmin();

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'user_id' => $user->id,
        'notification_channel_id' => $channel->id,
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('sendTest', $setting)
        ->assertNotified('Test webhook failed');
});
