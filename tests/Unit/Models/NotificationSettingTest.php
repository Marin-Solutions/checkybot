<?php

namespace Tests\Unit\Models;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;
use Tests\TestCase;

class NotificationSettingTest extends TestCase
{
    public function test_notification_setting_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $setting = NotificationSetting::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $setting->user);
        $this->assertEquals($user->id, $setting->user->id);
    }

    public function test_notification_setting_can_belong_to_website(): void
    {
        $website = Website::factory()->create();
        $setting = NotificationSetting::factory()->websiteScope()->create([
            'website_id' => $website->id,
        ]);

        $this->assertInstanceOf(Website::class, $setting->website);
        $this->assertEquals($website->id, $setting->website->id);
    }

    public function test_notification_setting_can_be_global_scope(): void
    {
        $setting = NotificationSetting::factory()->globalScope()->create();

        $this->assertEquals(NotificationScopesEnum::GLOBAL, $setting->scope);
        $this->assertNull($setting->website_id);
    }

    public function test_notification_setting_can_be_website_scope(): void
    {
        $setting = NotificationSetting::factory()->websiteScope()->create();

        $this->assertEquals(NotificationScopesEnum::WEBSITE, $setting->scope);
        $this->assertNotNull($setting->website_id);
    }

    public function test_notification_setting_can_use_email_channel(): void
    {
        $setting = NotificationSetting::factory()->email()->create();

        $this->assertEquals(NotificationChannelTypesEnum::MAIL, $setting->channel_type);
        $this->assertNotNull($setting->address);
        $this->assertNull($setting->notification_channel_id);
    }

    public function test_notification_setting_can_use_webhook_channel(): void
    {
        $setting = NotificationSetting::factory()->webhook()->create();

        $this->assertEquals(NotificationChannelTypesEnum::WEBHOOK, $setting->channel_type);
        $this->assertNotNull($setting->notification_channel_id);
    }

    public function test_notification_setting_can_be_for_all_checks(): void
    {
        $setting = NotificationSetting::factory()->create([
            'inspection' => WebsiteServicesEnum::ALL_CHECK,
        ]);

        $this->assertEquals(WebsiteServicesEnum::ALL_CHECK, $setting->inspection);
    }

    public function test_notification_setting_can_be_for_website_checks_only(): void
    {
        $setting = NotificationSetting::factory()->create([
            'inspection' => WebsiteServicesEnum::WEBSITE_CHECK,
        ]);

        $this->assertEquals(WebsiteServicesEnum::WEBSITE_CHECK, $setting->inspection);
    }

    public function test_notification_setting_can_be_for_api_monitors_only(): void
    {
        $setting = NotificationSetting::factory()->create([
            'inspection' => WebsiteServicesEnum::API_MONITOR,
        ]);

        $this->assertEquals(WebsiteServicesEnum::API_MONITOR, $setting->inspection);
    }

    public function test_notification_setting_can_be_active(): void
    {
        $setting = NotificationSetting::factory()->create(['flag_active' => true]);

        $this->assertTrue($setting->flag_active);
    }

    public function test_notification_setting_scope_returns_only_global_settings(): void
    {
        NotificationSetting::factory()->globalScope()->count(3)->create();
        NotificationSetting::factory()->websiteScope()->count(2)->create();

        $globalSettings = NotificationSetting::globalScope()->get();

        $this->assertCount(3, $globalSettings);
        $globalSettings->each(function ($setting) {
            $this->assertEquals(NotificationScopesEnum::GLOBAL, $setting->scope);
        });
    }

    public function test_notification_setting_scope_returns_only_website_settings(): void
    {
        NotificationSetting::factory()->globalScope()->count(3)->create();
        NotificationSetting::factory()->websiteScope()->count(2)->create();

        $websiteSettings = NotificationSetting::websiteScope()->get();

        $this->assertCount(2, $websiteSettings);
        $websiteSettings->each(function ($setting) {
            $this->assertEquals(NotificationScopesEnum::WEBSITE, $setting->scope);
        });
    }

    public function test_notification_setting_scope_returns_only_active_settings(): void
    {
        NotificationSetting::factory()->count(3)->create(['flag_active' => true]);
        NotificationSetting::factory()->count(2)->create(['flag_active' => false]);

        $activeSettings = NotificationSetting::active()->get();

        $this->assertCount(3, $activeSettings);
        $activeSettings->each(function ($setting) {
            $this->assertTrue($setting->flag_active);
        });
    }
}
