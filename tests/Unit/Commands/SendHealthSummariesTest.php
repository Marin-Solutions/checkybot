<?php

use App\Models\NotificationChannels;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('command sends only due health summaries', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = User::factory()->create();
    $notDueAt = now()->subMinutes(14);
    $due = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://example.test/due',
        'health_summary_interval_minutes' => 15,
        'health_summary_last_attempted_at' => now()->subMinutes(16),
    ]);
    $notDue = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://example.test/not-due',
        'health_summary_interval_minutes' => 15,
        'health_summary_last_attempted_at' => $notDueAt,
    ]);
    NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://example.test/disabled',
        'health_summary_interval_minutes' => null,
    ]);

    $this->artisan('notifications:send-health-summaries')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.test/due'
        && str_starts_with($request->data()['message'] ?? '', '📊 Checkybot summary'));

    expect($due->refresh()->health_summary_last_attempted_at->gt(now()->subMinute()))->toBeTrue()
        ->and($due->last_delivery_kind)->toBe('health_summary')
        ->and($notDue->refresh()->health_summary_last_attempted_at->toDateTimeString())->toBe($notDueAt->toDateTimeString());
});

test('command waits for the configured interval after a failed summary attempt', function () {
    Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

    $channel = NotificationChannels::factory()->create([
        'health_summary_interval_minutes' => 5,
        'health_summary_last_attempted_at' => null,
    ]);

    $this->artisan('notifications:send-health-summaries')->assertSuccessful();
    $this->artisan('notifications:send-health-summaries')->assertSuccessful();

    Http::assertSentCount(1);
    expect($channel->refresh()->last_delivery_succeeded)->toBeFalse()
        ->and($channel->health_summary_last_attempted_at)->not->toBeNull();
});
