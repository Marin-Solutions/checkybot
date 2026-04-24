<?php

use App\Filament\Resources\NotificationChannelsResource\Pages\ListNotificationChannels;
use App\Filament\Resources\NotificationSettingResource;
use App\Filament\Resources\NotificationSettingResource\Pages\ListNotificationSettings;
use App\Mail\HealthStatusAlert;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

// --- NotificationChannelsResource: Send Test action ---

test('send test action delivers webhook and surfaces success notification', function () {
    $this->createResourcePermissions('NotificationChannels');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['ok' => true], 200),
    ]);

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'request_body' => ['message' => '{message}'],
    ]);

    Livewire::test(ListNotificationChannels::class)
        ->callTableAction('send_test', $channel)
        ->assertNotified('Webhook test delivered successfully');

    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/webhook' && $request->method() === 'POST');
});

test('send test action surfaces failure notification when webhook returns non-200', function () {
    $this->createResourcePermissions('NotificationChannels');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['error' => 'bad token'], 500),
    ]);

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'request_body' => ['message' => '{message}'],
    ]);

    Livewire::test(ListNotificationChannels::class)
        ->callTableAction('send_test', $channel)
        ->assertNotified('Webhook test failed');
});

// --- NotificationSettingResource: Send Test action ---

test('send test action sends email for mail channel setting', function () {
    $this->createResourcePermissions('NotificationSetting');

    $user = $this->actingAsSuperAdmin();

    Mail::fake();

    $setting = NotificationSetting::factory()->email()->create([
        'user_id' => $user->id,
        'address' => 'inbox@example.com',
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('send_test', $setting)
        ->assertNotified('Test email sent');

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->hasTo('inbox@example.com');
    });
});

test('send test action triggers webhook for webhook channel setting', function () {
    $this->createResourcePermissions('NotificationSetting');
    $this->createResourcePermissions('NotificationChannels');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['ok' => true], 200),
    ]);

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'request_body' => ['message' => '{message}'],
    ]);

    $setting = NotificationSetting::factory()->webhook()->create([
        'user_id' => $user->id,
        'notification_channel_id' => $channel->id,
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('send_test', $setting)
        ->assertNotified('Webhook test delivered successfully');

    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/webhook');
});

test('send test action warns when webhook setting has no linked channel', function () {
    $this->createResourcePermissions('NotificationSetting');

    $user = $this->actingAsSuperAdmin();

    $setting = NotificationSetting::factory()->webhook()->create([
        'user_id' => $user->id,
        'notification_channel_id' => null,
    ]);

    Livewire::test(ListNotificationSettings::class)
        ->callTableAction('send_test', $setting)
        ->assertNotified('Missing webhook channel');
});

test('send test action catches unhandled channel types and notifies gracefully', function () {
    // Build an in-memory setting whose cast resolves to null, simulating a
    // future enum case that the match expression does not yet handle. This
    // verifies that the try/catch around the match keeps users out of an
    // uncaught UnhandledMatchError page.
    $setting = new NotificationSetting;
    $setting->forceFill([
        'user_id' => 1,
        'channel_type' => null,
        'address' => null,
        'flag_active' => true,
    ]);

    NotificationSettingResource::sendTestNotification($setting);

    Notification::assertNotified('Test not supported');
});
