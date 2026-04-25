<?php

use App\Filament\Resources\NotificationSettingResource\Pages\ListNotificationSettings;
use App\Mail\HealthStatusAlert;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use Filament\Notifications\Livewire\Notifications;
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
        ->callTableAction('sendTest', $setting);

    // assertNotified() consumes the session-flashed notification, so read the
    // body first via the same path Filament's own assertNotified() uses.
    $component = new Notifications;
    $component->mount();
    $notification = $component->notifications->last();

    expect($notification)->not->toBeNull();
    expect($notification->getTitle())->toBe('Test webhook failed');
    expect($notification->getBody())->toContain('HTTP 502');
});

test('global notification list shows empty state with create CTA when no rules exist', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListNotificationSettings::class)
        ->assertSee('No global notification rules yet')
        ->assertSee('Create a rule to be alerted by email or webhook when any of your monitors changes state.')
        ->assertSee('Rules added here apply automatically to every website with the matching monitor enabled.')
        ->assertSee('Add notification rule');
});
