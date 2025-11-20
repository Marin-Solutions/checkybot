<?php

namespace Tests\Unit\Models;

use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use Tests\TestCase;

class SeoScheduleTest extends TestCase
{
    public function test_seo_schedule_belongs_to_website(): void
    {
        $website = Website::factory()->create();
        $schedule = SeoSchedule::factory()->create(['website_id' => $website->id]);

        $this->assertInstanceOf(Website::class, $schedule->website);
        $this->assertEquals($website->id, $schedule->website->id);
    }

    public function test_seo_schedule_belongs_to_creator(): void
    {
        $user = User::factory()->create();
        $schedule = SeoSchedule::factory()->create(['created_by' => $user->id]);

        $this->assertInstanceOf(User::class, $schedule->creator);
        $this->assertEquals($user->id, $schedule->creator->id);
    }

    public function test_seo_schedule_can_be_daily(): void
    {
        $schedule = SeoSchedule::factory()->daily()->create();

        $this->assertEquals('daily', $schedule->frequency);
        $this->assertNull($schedule->schedule_day);
    }

    public function test_seo_schedule_can_be_weekly(): void
    {
        $schedule = SeoSchedule::factory()->weekly()->create();

        $this->assertEquals('weekly', $schedule->frequency);
        $this->assertEquals('monday', $schedule->schedule_day);
    }

    public function test_seo_schedule_can_be_monthly(): void
    {
        $schedule = SeoSchedule::factory()->monthly()->create();

        $this->assertEquals('monthly', $schedule->frequency);
        $this->assertEquals(1, $schedule->schedule_day);
    }

    public function test_seo_schedule_can_be_active(): void
    {
        $schedule = SeoSchedule::factory()->create(['is_active' => true]);

        $this->assertTrue($schedule->is_active);
    }

    public function test_seo_schedule_can_be_inactive(): void
    {
        $schedule = SeoSchedule::factory()->inactive()->create();

        $this->assertFalse($schedule->is_active);
    }

    public function test_seo_schedule_casts_dates(): void
    {
        $schedule = SeoSchedule::factory()->create([
            'last_run_at' => now()->subDay(),
            'next_run_at' => now()->addDay(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $schedule->last_run_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $schedule->next_run_at);
    }

    public function test_seo_schedule_scope_returns_due_schedules(): void
    {
        SeoSchedule::factory()->create([
            'is_active' => true,
            'next_run_at' => now()->subHour(),
        ]);

        SeoSchedule::factory()->create([
            'is_active' => true,
            'next_run_at' => now()->addHour(),
        ]);

        $dueSchedules = SeoSchedule::due()->get();

        $this->assertCount(1, $dueSchedules);
    }

    public function test_seo_schedule_scope_only_returns_active_schedules(): void
    {
        SeoSchedule::factory()->create([
            'is_active' => false,
            'next_run_at' => now()->subHour(),
        ]);

        SeoSchedule::factory()->create([
            'is_active' => true,
            'next_run_at' => now()->subHour(),
        ]);

        $dueSchedules = SeoSchedule::due()->get();

        $this->assertCount(1, $dueSchedules);
        $this->assertTrue($dueSchedules->first()->is_active);
    }

    public function test_seo_schedule_updates_next_run_time(): void
    {
        $schedule = SeoSchedule::factory()->daily()->create([
            'schedule_time' => '14:30:00',
            'next_run_at' => now(),
        ]);

        $schedule->updateNextRun();

        $this->assertNotNull($schedule->last_run_at);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertTrue($schedule->next_run_at->isFuture());
    }

    public function test_seo_schedule_calculates_next_daily_run(): void
    {
        $schedule = SeoSchedule::factory()->daily()->create([
            'schedule_time' => '09:00:00',
        ]);

        $schedule->updateNextRun();

        $nextRun = $schedule->fresh()->next_run_at;
        $this->assertEquals(9, $nextRun->hour);
        $this->assertEquals(0, $nextRun->minute);
        $this->assertTrue($nextRun->isFuture());
    }

    public function test_seo_schedule_has_schedule_time(): void
    {
        $schedule = SeoSchedule::factory()->create([
            'schedule_time' => '14:30:00',
        ]);

        $this->assertEquals('14:30:00', $schedule->schedule_time);
    }
}
