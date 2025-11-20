<?php

namespace Tests\Unit\Models;

use App\Models\NotificationSetting;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    public function test_website_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['created_by' => $user->id]);

        $this->assertInstanceOf(User::class, $website->user);
        $this->assertEquals($user->id, $website->user->id);
    }

    public function test_website_has_many_seo_checks(): void
    {
        $website = Website::factory()->create();
        SeoCheck::factory()->count(3)->create(['website_id' => $website->id]);

        $this->assertCount(3, $website->seoChecks);
        $this->assertInstanceOf(SeoCheck::class, $website->seoChecks->first());
    }

    public function test_website_has_one_latest_seo_check(): void
    {
        $website = Website::factory()->create();

        SeoCheck::factory()->create([
            'website_id' => $website->id,
            'created_at' => now()->subDays(2),
        ]);

        $latestCheck = SeoCheck::factory()->create([
            'website_id' => $website->id,
            'created_at' => now(),
        ]);

        $this->assertEquals($latestCheck->id, $website->latestSeoCheck->id);
    }

    public function test_website_has_one_seo_schedule(): void
    {
        $website = Website::factory()->create();
        $schedule = SeoSchedule::factory()->create(['website_id' => $website->id]);

        $this->assertInstanceOf(SeoSchedule::class, $website->seoSchedule);
        $this->assertEquals($schedule->id, $website->seoSchedule->id);
    }

    public function test_website_has_many_notification_channels(): void
    {
        $website = Website::factory()->create();
        NotificationSetting::factory()
            ->websiteScope()
            ->count(2)
            ->create([
                'website_id' => $website->id,
            ]);

        $this->assertCount(2, $website->notificationChannels);
    }

    public function test_website_has_many_log_history(): void
    {
        $website = Website::factory()->create();
        WebsiteLogHistory::factory()->count(5)->create(['website_id' => $website->id]);

        $this->assertCount(5, $website->logHistory);
    }

    public function test_website_url_is_required(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Website::factory()->create(['url' => null]);
    }

    public function test_website_can_enable_uptime_check(): void
    {
        $website = Website::factory()->create(['uptime_check' => false]);

        $website->update(['uptime_check' => true]);

        $this->assertTrue($website->fresh()->uptime_check);
    }

    public function test_website_can_set_uptime_interval(): void
    {
        $website = Website::factory()->create(['uptime_interval' => 60]);

        $this->assertEquals(60, $website->uptime_interval);
    }

    public function test_website_tracks_ssl_expiry_date(): void
    {
        $expiryDate = now()->addDays(30);
        $website = Website::factory()->create(['ssl_expiry_date' => $expiryDate]);

        $this->assertEquals($expiryDate->format('Y-m-d'), $website->ssl_expiry_date->format('Y-m-d'));
    }
}
