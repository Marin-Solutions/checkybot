<?php

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use App\Services\CheckerOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('laravel keeps the check when no go owner is set', function () {
    $ownership = app(CheckerOwnership::class);

    expect($ownership->goOwns('uptime'))->toBeFalse();

    $token = $ownership->acquire('uptime', 'uptime:1', 30);

    expect($token)->not->toBeNull();
    expect($ownership->claim('uptime:1', 'other', 30))->toBeFalse();

    $ownership->release('uptime:1', $token);
    expect($ownership->claim('uptime:1', 'other', 30))->toBeTrue();
});

test('the uptime command does not queue work while go owns it', function () {
    Queue::fake();
    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
        'latest_scheduled_result_at' => null,
    ]);
    DB::table('checker_settings')->where('setting_key', 'uptime_owner')->update(['setting_value' => 'go']);

    $this->artisan('website:log-uptime-ssl')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('a queued uptime job does not run or clear a manual request while go owns it', function () {
    Http::fake();
    $website = Website::factory()->create([
        'uptime_check' => true,
        'diagnostic_queued_at' => now(),
    ]);
    DB::table('checker_settings')->where('setting_key', 'uptime_owner')->update(['setting_value' => 'go']);

    (new LogUptimeSslJob($website, onDemand: true))->handle(app(\App\Services\SslCertificateService::class));

    Http::assertNothingSent();
    expect($website->fresh()->diagnostic_queued_at)->not->toBeNull();
});

test('the outbox drain marks a transition delivered when the website is gone', function () {
    DB::table('checker_outbox')->insert([
        'kind' => 'website_transition',
        'subject_id' => 999999,
        'event' => 'heartbeat',
        'status' => 'danger',
        'summary' => 'gone',
        'attempts' => 0,
        'created_at' => now(),
    ]);

    $this->artisan('checker:drain-outbox')->assertSuccessful();

    expect(DB::table('checker_outbox')->whereNull('processed_at')->count())->toBe(0);
});
