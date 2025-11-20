<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;

test('notification setting belongs to user', function () {
    $user = User::factory()->create();
    $setting = NotificationSetting::factory()->create(['user_id' => $user->id]);

    expect($setting->user)->toBeInstanceOf(User::class);
    expect($setting->user->id)->toBe($user->id);
});

test('notification setting can belong to website', function () {
    $website = Website::factory()->create();
    $setting = NotificationSetting::factory()->websiteScope()->create([
        'website_id' => $website->id,
    ]);

    expect($setting->website)->toBeInstanceOf(Website::class);
    expect($setting->website->id)->toBe($website->id);
});

test('notification setting can be global scope', function () {
    $setting = NotificationSetting::factory()->globalScope()->create();

    expect($setting->scope)->toBe(NotificationScopesEnum::GLOBAL);
    expect($setting->website_id)->toBeNull();
});

test('notification setting can be website scope', function () {
    $setting = NotificationSetting::factory()->websiteScope()->create();

    expect($setting->scope)->toBe(NotificationScopesEnum::WEBSITE);
    expect($setting->website_id)->not->toBeNull();
});

test('notification setting can use email channel', function () {
    $setting = NotificationSetting::factory()->email()->create();

    expect($setting->channel_type)->toBe(NotificationChannelTypesEnum::MAIL);
    expect($setting->address)->not->toBeNull();
    expect($setting->notification_channel_id)->toBeNull();
});

test('notification setting can use webhook channel', function () {
    $setting = NotificationSetting::factory()->webhook()->create();

    expect($setting->channel_type)->toBe(NotificationChannelTypesEnum::WEBHOOK);
    expect($setting->notification_channel_id)->not->toBeNull();
});

test('notification setting can be for all checks', function () {
    $setting = NotificationSetting::factory()->create([
        'inspection' => WebsiteServicesEnum::ALL_CHECK,
    ]);

    expect($setting->inspection)->toBe(WebsiteServicesEnum::ALL_CHECK);
});

test('notification setting can be for website checks only', function () {
    $setting = NotificationSetting::factory()->create([
        'inspection' => WebsiteServicesEnum::WEBSITE_CHECK,
    ]);

    expect($setting->inspection)->toBe(WebsiteServicesEnum::WEBSITE_CHECK);
});

test('notification setting can be for api monitors only', function () {
    $setting = NotificationSetting::factory()->create([
        'inspection' => WebsiteServicesEnum::API_MONITOR,
    ]);

    expect($setting->inspection)->toBe(WebsiteServicesEnum::API_MONITOR);
});

test('notification setting can be active', function () {
    $setting = NotificationSetting::factory()->create(['flag_active' => true]);

    expect($setting->flag_active)->toBeTrue();
});

test('notification setting scope returns only global settings', function () {
    NotificationSetting::factory()->globalScope()->count(3)->create();
    NotificationSetting::factory()->websiteScope()->count(2)->create();

    $globalSettings = NotificationSetting::globalScope()->get();

    expect($globalSettings)->toHaveCount(3);
    $globalSettings->each(function ($setting) {
        expect($setting->scope)->toBe(NotificationScopesEnum::GLOBAL);
    });
});

test('notification setting scope returns only website settings', function () {
    NotificationSetting::factory()->globalScope()->count(3)->create();
    NotificationSetting::factory()->websiteScope()->count(2)->create();

    $websiteSettings = NotificationSetting::websiteScope()->get();

    expect($websiteSettings)->toHaveCount(2);
    $websiteSettings->each(function ($setting) {
        expect($setting->scope)->toBe(NotificationScopesEnum::WEBSITE);
    });
});

test('notification setting scope returns only active settings', function () {
    NotificationSetting::factory()->count(3)->create(['flag_active' => true]);
    NotificationSetting::factory()->count(2)->create(['flag_active' => false]);

    $activeSettings = NotificationSetting::active()->get();

    expect($activeSettings)->toHaveCount(3);
    $activeSettings->each(function ($setting) {
        expect($setting->flag_active)->toBeTrue();
    });
});
