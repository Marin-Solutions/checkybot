<?php

use App\Enums\WebsiteServicesEnum;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use Illuminate\Support\Facades\Mail;

test('website notifications are skipped while silenced_until is in the future', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'silenced_until' => now()->addHour(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
            'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
        ]);

    app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    Mail::assertNothingSent();
});

test('website notifications resume after silenced_until passes', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
            'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
        ]);

    app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    Mail::assertSent(HealthStatusAlert::class);
});

test('website notifications are delivered when silenced_until is null', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
            'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
        ]);

    app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    Mail::assertSent(HealthStatusAlert::class);
});

test('api monitor notifications are skipped while silenced_until is in the future', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'silenced_until' => now()->addHours(4),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => WebsiteServicesEnum::API_MONITOR->value,
        ]);

    app(HealthEventNotificationService::class)
        ->notifyApi($monitor, 'heartbeat', 'danger', 'Endpoint returned HTTP 500.');

    Mail::assertNothingSent();
});

test('api monitor notifications resume after silenced_until passes', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => WebsiteServicesEnum::API_MONITOR->value,
        ]);

    app(HealthEventNotificationService::class)
        ->notifyApi($monitor, 'heartbeat', 'danger', 'Endpoint returned HTTP 500.');

    Mail::assertSent(HealthStatusAlert::class);
});

test('isSilenced helper returns true only while silenced_until is in the future', function () {
    $silenced = Website::factory()->make(['silenced_until' => now()->addHour()]);
    $expired = Website::factory()->make(['silenced_until' => now()->subMinute()]);
    $clear = Website::factory()->make(['silenced_until' => null]);

    expect($silenced->isSilenced())->toBeTrue()
        ->and($expired->isSilenced())->toBeFalse()
        ->and($clear->isSilenced())->toBeFalse();

    $apiSilenced = MonitorApis::factory()->make(['silenced_until' => now()->addHour()]);
    $apiClear = MonitorApis::factory()->make(['silenced_until' => null]);

    expect($apiSilenced->isSilenced())->toBeTrue()
        ->and($apiClear->isSilenced())->toBeFalse();
});
