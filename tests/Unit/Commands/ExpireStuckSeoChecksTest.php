<?php

use App\Console\Commands\ExpireStuckSeoChecks;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('command can be executed', function () {
    $this->artisan('seo:expire-stuck')
        ->expectsOutput('Expired 0 stuck SEO checks (0 pending, 0 running).')
        ->assertSuccessful();
});

test('command marks old pending seo checks as failed with failure details', function () {
    Carbon::setTestNow('2026-05-06 12:00:00');

    $website = Website::factory()->create(['url' => 'https://example.com']);

    $seoCheck = SeoCheck::factory()->create([
        'website_id' => $website->id,
        'status' => SeoCheck::STATUS_PENDING,
        'created_at' => now()->subMinutes(61),
        'updated_at' => now()->subMinutes(61),
    ]);

    $this->artisan('seo:expire-stuck')
        ->expectsOutput('Expired 1 stuck SEO checks (1 pending, 0 running).')
        ->assertSuccessful();

    $seoCheck->refresh();

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($seoCheck->finished_at?->toDateTimeString())->toBe('2026-05-06 12:00:00')
        ->and($seoCheck->failure_summary)->toBe('SEO check expired after waiting in the queue for more than 60 minutes.')
        ->and($seoCheck->failure_context)->toMatchArray([
            'expired_by' => ExpireStuckSeoChecks::class,
            'previous_status' => SeoCheck::STATUS_PENDING,
            'threshold_minutes' => 60,
            'website_url' => 'https://example.com',
        ]);
});

test('command marks long running seo checks as failed with failure details', function () {
    Carbon::setTestNow('2026-05-06 12:00:00');

    $seoCheck = SeoCheck::factory()->running()->create([
        'started_at' => now()->subMinutes(31),
        'total_urls_crawled' => 7,
        'total_crawlable_urls' => 20,
    ]);

    $this->artisan('seo:expire-stuck')
        ->expectsOutput('Expired 1 stuck SEO checks (0 pending, 1 running).')
        ->assertSuccessful();

    $seoCheck->refresh();

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($seoCheck->failure_summary)->toBe('SEO check expired after running for more than 30 minutes without finishing.')
        ->and($seoCheck->failure_context)->toMatchArray([
            'expired_by' => ExpireStuckSeoChecks::class,
            'previous_status' => SeoCheck::STATUS_RUNNING,
            'threshold_minutes' => 30,
            'total_urls_crawled' => 7,
            'total_crawlable_urls' => 20,
        ]);
});

test('command uses created at for running checks that never recorded start time', function () {
    Carbon::setTestNow('2026-05-06 12:00:00');

    $seoCheck = SeoCheck::factory()->create([
        'status' => SeoCheck::STATUS_RUNNING,
        'started_at' => null,
        'created_at' => now()->subMinutes(31),
        'updated_at' => now()->subMinutes(31),
    ]);

    $this->artisan('seo:expire-stuck')
        ->expectsOutput('Expired 1 stuck SEO checks (0 pending, 1 running).')
        ->assertSuccessful();

    expect($seoCheck->fresh()->status)->toBe(SeoCheck::STATUS_FAILED);
});

test('command leaves recent and terminal seo checks untouched', function () {
    Carbon::setTestNow('2026-05-06 12:00:00');

    $recentPending = SeoCheck::factory()->create([
        'status' => SeoCheck::STATUS_PENDING,
        'created_at' => now()->subMinutes(59),
    ]);
    $recentRunning = SeoCheck::factory()->running()->create([
        'started_at' => now()->subMinutes(29),
    ]);
    $completed = SeoCheck::factory()->completed()->create([
        'created_at' => now()->subHours(2),
        'started_at' => now()->subHours(2),
    ]);
    $failed = SeoCheck::factory()->failed()->create([
        'created_at' => now()->subHours(2),
        'started_at' => now()->subHours(2),
    ]);
    $cancelled = SeoCheck::factory()->cancelled()->create([
        'created_at' => now()->subHours(2),
        'started_at' => now()->subHours(2),
    ]);

    $this->artisan('seo:expire-stuck')
        ->expectsOutput('Expired 0 stuck SEO checks (0 pending, 0 running).')
        ->assertSuccessful();

    expect($recentPending->fresh()->status)->toBe(SeoCheck::STATUS_PENDING)
        ->and($recentRunning->fresh()->status)->toBe(SeoCheck::STATUS_RUNNING)
        ->and($completed->fresh()->status)->toBe(SeoCheck::STATUS_COMPLETED)
        ->and($failed->fresh()->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($cancelled->fresh()->status)->toBe(SeoCheck::STATUS_CANCELLED);
});

test('command supports custom expiration thresholds', function () {
    Carbon::setTestNow('2026-05-06 12:00:00');

    $pending = SeoCheck::factory()->create([
        'status' => SeoCheck::STATUS_PENDING,
        'created_at' => now()->subMinutes(11),
    ]);
    $running = SeoCheck::factory()->running()->create([
        'started_at' => now()->subMinutes(6),
    ]);

    $this->artisan('seo:expire-stuck --pending-minutes=10 --running-minutes=5')
        ->expectsOutput('Expired 2 stuck SEO checks (1 pending, 1 running).')
        ->assertSuccessful();

    expect($pending->fresh()->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($running->fresh()->status)->toBe(SeoCheck::STATUS_FAILED);
});

test('scheduled seo expiration command uses overlap protection', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->command, 'seo:expire-stuck')
    );

    expect($event)->not->toBeNull();
    expect($event->withoutOverlapping)->toBeTrue();
});

test('scheduled seo expiration runs before scheduled seo checks start', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->values();

    $expireIndex = $commands->search(fn (string $command) => str_contains($command, 'seo:expire-stuck'));
    $runIndex = $commands->search(fn (string $command) => str_contains($command, 'seo:run-scheduled'));

    expect($expireIndex)->not->toBeFalse()
        ->and($runIndex)->not->toBeFalse()
        ->and($expireIndex)->toBeLessThan($runIndex);
});
