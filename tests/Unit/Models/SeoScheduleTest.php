<?php

use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;

test('seo schedule belongs to website', function () {
    $website = Website::factory()->create();
    $schedule = SeoSchedule::factory()->create(['website_id' => $website->id]);

    expect($schedule->website)->toBeInstanceOf(Website::class);
    expect($schedule->website->id)->toBe($website->id);
});

test('seo schedule belongs to creator', function () {
    $user = User::factory()->create();
    $schedule = SeoSchedule::factory()->create(['created_by' => $user->id]);

    expect($schedule->creator)->toBeInstanceOf(User::class);
    expect($schedule->creator->id)->toBe($user->id);
});

test('seo schedule can be daily', function () {
    $schedule = SeoSchedule::factory()->daily()->create();

    expect($schedule->frequency)->toBe('daily');
    expect($schedule->schedule_day)->toBeNull();
});

test('seo schedule can be weekly', function () {
    $schedule = SeoSchedule::factory()->weekly()->create();

    expect($schedule->frequency)->toBe('weekly');
    expect($schedule->schedule_day)->toBe('monday');
});

test('seo schedule can be monthly', function () {
    $schedule = SeoSchedule::factory()->monthly()->create();

    expect($schedule->frequency)->toBe('monthly');
    expect($schedule->schedule_day)->toBe(1);
});

test('seo schedule can be active', function () {
    $schedule = SeoSchedule::factory()->create(['is_active' => true]);

    expect($schedule->is_active)->toBeTrue();
});

test('seo schedule can be inactive', function () {
    $schedule = SeoSchedule::factory()->inactive()->create();

    expect($schedule->is_active)->toBeFalse();
});

test('seo schedule casts dates', function () {
    $schedule = SeoSchedule::factory()->create([
        'last_run_at' => now()->subDay(),
        'next_run_at' => now()->addDay(),
    ]);

    expect($schedule->last_run_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($schedule->next_run_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('seo schedule scope returns due schedules', function () {
    SeoSchedule::factory()->create([
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    SeoSchedule::factory()->create([
        'is_active' => true,
        'next_run_at' => now()->addHour(),
    ]);

    $dueSchedules = SeoSchedule::due()->get();

    expect($dueSchedules)->toHaveCount(1);
});

test('seo schedule scope only returns active schedules', function () {
    SeoSchedule::factory()->create([
        'is_active' => false,
        'next_run_at' => now()->subHour(),
    ]);

    SeoSchedule::factory()->create([
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $dueSchedules = SeoSchedule::due()->get();

    expect($dueSchedules)->toHaveCount(1);
    expect($dueSchedules->first()->is_active)->toBeTrue();
});

test('seo schedule updates next run time', function () {
    $schedule = SeoSchedule::factory()->daily()->create([
        'schedule_time' => '14:30:00',
        'next_run_at' => now(),
    ]);

    $schedule->updateNextRun();

    expect($schedule->last_run_at)->not->toBeNull();
    expect($schedule->next_run_at)->not->toBeNull();
    expect($schedule->next_run_at->isFuture())->toBeTrue();
});

test('seo schedule calculates next daily run', function () {
    $schedule = SeoSchedule::factory()->daily()->create([
        'schedule_time' => '09:00:00',
    ]);

    $schedule->updateNextRun();

    $nextRun = $schedule->fresh()->next_run_at;
    expect($nextRun->hour)->toBe(9);
    expect($nextRun->minute)->toBe(0);
    expect($nextRun->isFuture())->toBeTrue();
});

test('seo schedule has schedule time', function () {
    $schedule = SeoSchedule::factory()->create([
        'schedule_time' => '14:30:00',
    ]);

    expect($schedule->schedule_time)->toBe('14:30:00');
});
