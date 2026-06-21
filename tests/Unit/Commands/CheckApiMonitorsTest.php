<?php

use App\Jobs\RunScheduledApiMonitorJob;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function seedScheduledApiMonitorResult(MonitorApis $monitor, \Illuminate\Support\Carbon $createdAt): MonitorApiResult
{
    return MonitorApiResult::factory()
        ->successful()
        ->create([
            'monitor_api_id' => $monitor->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
}

test('command checks all active api monitors', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/health',
        'data_path' => 'data.status',
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
});

test('command skips disabled api monitors', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->disabled()->create([
        'url' => 'https://api.example.com/disabled-health',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'stale_at' => null,
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
    Http::assertNothingSent();

    $monitor->refresh();

    expect($monitor->current_status)->toBe('unknown');
    expect($monitor->last_heartbeat_at)->toBeNull();
});

test('command queues due api monitor jobs without running http checks inline', function () {
    Queue::fake();

    Http::fake(function (): never {
        throw new ConnectionException('The scheduler command should not perform HTTP requests inline.');
    });

    $dueMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/queued-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($dueMonitor, now()->subMinutes(16));

    $skippedMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/skipped-queued-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($skippedMonitor, now()->subMinutes(5));

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 1 API monitor jobs.')
        ->assertSuccessful();

    Queue::assertPushed(RunScheduledApiMonitorJob::class, 1);
    Queue::assertPushedOn(RunScheduledApiMonitorJob::QUEUE, RunScheduledApiMonitorJob::class);
    Queue::assertPushed(
        RunScheduledApiMonitorJob::class,
        fn (RunScheduledApiMonitorJob $job): bool => $job->monitor->is($dueMonitor)
            && $job->queue === RunScheduledApiMonitorJob::QUEUE
    );
    Queue::assertNotPushed(
        RunScheduledApiMonitorJob::class,
        fn (RunScheduledApiMonitorJob $job): bool => $job->monitor->is($skippedMonitor)
    );

    Http::assertNothingSent();
});

test('command logs dispatch failures and continues queueing due api monitors', function () {
    Log::spy();

    $failedMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/failed-dispatch-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($failedMonitor, now()->subMinutes(16));

    $queuedMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/queued-after-dispatch-failure',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($queuedMonitor, now()->subMinutes(16));

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->twice()
        ->andReturnUsing(function (RunScheduledApiMonitorJob $job) use ($failedMonitor) {
            if ($job->monitor->is($failedMonitor)) {
                throw new RuntimeException('Queue backend unavailable.');
            }

            return null;
        });
    app()->instance(Dispatcher::class, $dispatcher);

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 1 API monitor jobs.')
        ->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->once()
        ->with(
            'Failed to queue scheduled API monitor job.',
            Mockery::on(fn (array $context): bool => ($context['monitor_id'] ?? null) === $failedMonitor->id
                && ($context['exception'] ?? null) === RuntimeException::class
                && ($context['message'] ?? null) === 'Queue backend unavailable.')
        );
});

test('command logs due query failures and exits successfully', function () {
    Log::spy();

    MonitorApis::factory()->create([
        'url' => 'https://api.example.com/query-failure-health',
        'package_interval' => '15m',
    ]);

    Schema::drop('monitor_api_results');

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 0 API monitor jobs.')
        ->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->once()
        ->with(
            'Failed to query due API monitor checks.',
            Mockery::on(fn (array $context): bool => filled($context['exception'] ?? null)
                && str_contains((string) ($context['message'] ?? ''), 'monitor_api_results'))
        );
});

test('command only checks api monitors when their polling interval is due', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $dueMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/due-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($dueMonitor, now()->subMinutes(16));

    $skippedMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/skipped-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($skippedMonitor, now()->subMinutes(5));

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 1 API monitor jobs.')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $dueMonitor->id,
    ]);

    expect(MonitorApiResult::where('monitor_api_id', $skippedMonitor->id)->count())->toBe(1);

    Http::assertSentCount(1);
});

test('command does not hydrate enabled api monitors before their polling interval is due', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $dueMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/due-query-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($dueMonitor, now()->subMinutes(16));

    $skippedMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/skipped-query-health',
        'package_interval' => '15m',
    ]);
    seedScheduledApiMonitorResult($skippedMonitor, now()->subMinutes(5));

    $retrievedMonitorIds = [];
    MonitorApis::retrieved(function (MonitorApis $monitor) use (&$retrievedMonitorIds): void {
        $retrievedMonitorIds[] = $monitor->id;
    });

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 1 API monitor jobs.')
        ->assertSuccessful();

    expect($retrievedMonitorIds)
        ->toContain($dueMonitor->id)
        ->not->toContain($skippedMonitor->id);

    Http::assertSentCount(1);
});

test('command checks interval monitors without a prior heartbeat immediately', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/first-health',
        'package_interval' => '1h',
        'last_heartbeat_at' => null,
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
});

test('command honors package-style api monitor intervals', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/package-style-health',
        'package_interval' => 'every_5_minutes',
    ]);
    seedScheduledApiMonitorResult($monitor, now()->subMinutes(2));

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 0 API monitor jobs.')
        ->assertSuccessful();

    expect(MonitorApiResult::where('monitor_api_id', $monitor->id)->count())->toBe(1);

    Http::assertNothingSent();
});

test('command honors zero padded api monitor intervals accepted by parser', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $compactMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/zero-padded-compact-health',
        'package_interval' => '05m',
    ]);
    seedScheduledApiMonitorResult($compactMonitor, now()->subMinutes(2));

    $legacyMonitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/zero-padded-legacy-health',
        'package_interval' => 'every_05_minutes',
    ]);
    seedScheduledApiMonitorResult($legacyMonitor, now()->subMinutes(2));

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 0 API monitor jobs.')
        ->assertSuccessful();

    expect(MonitorApiResult::where('monitor_api_id', $compactMonitor->id)->count())->toBe(1)
        ->and(MonitorApiResult::where('monitor_api_id', $legacyMonitor->id)->count())->toBe(1);

    Http::assertNothingSent();
});

test('command falls back to default cadence when package_interval is invalid', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    Log::spy();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/invalid-interval-health',
        'package_interval' => 'bad_value',
        'last_heartbeat_at' => now()->subSeconds(30),
    ]);

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 1 API monitor jobs.')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'API monitor has an invalid polling interval; running on the default cadence.',
            \Mockery::on(fn (array $context): bool => ($context['package_interval'] ?? null) === 'bad_value')
        );
});

test('command treats monitor intervals as minute-granular scheduler buckets', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-26 12:01:00'));

    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/minute-boundary-health',
        'package_interval' => '1m',
    ]);
    seedScheduledApiMonitorResult($monitor, \Illuminate\Support\Carbon::parse('2026-04-26 12:00:20'));

    $this->artisan('monitor:check-apis')
        ->expectsOutput('Queued 1 API monitor jobs.')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);

    $this->travelBack();
});

test('command records failed checks', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 500),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/health',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
    ]);
});

test('command records transport evidence when api check fails before http response', function () {
    Http::fake(function (): never {
        throw new ConnectionException('cURL error 6: Could not resolve host: missing.example');
    });

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://missing.example/health',
        'save_failed_response' => true,
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('danger')
        ->and($monitor->status_summary)->toBe('API check failed before an HTTP response: DNS lookup failed.')
        ->and($result?->http_code)->toBe(0)
        ->and($result?->status)->toBe('danger')
        ->and($result?->summary)->toBe('API check failed before an HTTP response: DNS lookup failed.')
        ->and($result?->transport_error_type)->toBe('dns')
        ->and($result?->transport_error_message)->toContain('Could not resolve host')
        ->and($result?->transport_error_code)->toBe(6);
});

test('command treats matching expected 404 status as healthy', function () {
    Http::fake([
        '*' => Http::response(['message' => 'missing by design'], 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/missing',
        'expected_status' => 404,
        'data_path' => '',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('healthy')
        ->and($monitor->status_summary)->toBe('API check succeeded with HTTP status 404.')
        ->and($result?->is_success)->toBeTrue()
        ->and($result?->status)->toBe('healthy')
        ->and($result?->summary)->toBe('API check succeeded with HTTP status 404.');
});

test('command treats matching expected 404 with failed assertions as warning', function () {
    Http::fake([
        '*' => Http::response(['message' => 'missing by design'], 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/missing-with-assertion',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning')
        ->and($result?->is_success)->toBeFalse()
        ->and($result?->status)->toBe('warning');
});

test('command treats matching expected 404 with invalid json as warning', function () {
    Http::fake([
        '*' => Http::response('not-json', 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/malformed-missing',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning')
        ->and($result?->is_success)->toBeFalse()
        ->and($result?->status)->toBe('warning')
        ->and(collect($result?->failed_assertions)->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === '_response_body'
                && ($assertion['type'] ?? null) === 'json_valid'
                && ($assertion['message'] ?? null) === 'Invalid JSON response: Syntax error'
                && ($assertion['expected'] ?? null) === 'valid JSON'
                && ($assertion['actual'] ?? null) === 'Syntax error'
        ))->toBeTrue();
});

test('command treats matching expected 404 with literal null json body as warning', function () {
    Http::fake([
        '*' => Http::response('null', 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/null-body',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning')
        ->and($result?->is_success)->toBeFalse()
        ->and($result?->status)->toBe('warning')
        ->and(collect($result?->failed_assertions)->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === 'data.status'
                && ($assertion['message'] ?? null) === 'Value does not exist at path'
        ))->toBeTrue();
});

test('command validates assertions', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create();

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($result->is_success)->toBeTrue();
});

test('command records warning status history and notifies for package-managed assertion failures', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'package-health',
        'url' => 'https://api.example.com/health',
        'source' => 'package',
        'package_name' => 'package-health',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning');
    expect($result?->status)->toBe('warning');

    Mail::assertSent(HealthStatusAlert::class);
});

test('command sends recovery notifications when a package-managed api monitor returns to healthy', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'package-health',
        'url' => 'https://api.example.com/health',
        'source' => 'package',
        'package_name' => 'package-health',
        'package_interval' => '5m',
        'current_status' => 'danger',
        'last_heartbeat_at' => now()->subMinutes(6),
        'stale_at' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();

    expect($monitor->current_status)->toBe('healthy')
        ->and($monitor->stale_at)->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy'
            && $mail->summary === 'API check succeeded with HTTP status 200.';
    });
});

test('command sends recovery notifications when a warning api monitor returns to healthy', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'package-health',
        'url' => 'https://api.example.com/health',
        'source' => 'package',
        'package_name' => 'package-health',
        'package_interval' => '5m',
        'current_status' => 'warning',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy';
    });
});

test('command sends notifications for failed manual api monitor regressions', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'manual-health',
        'url' => 'https://api.example.com/health',
        'source' => 'manual',
        'current_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();

    expect($monitor->current_status)->toBe('warning');

    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('command sends recovery notifications when a manual api monitor returns to healthy', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'manual-health',
        'url' => 'https://api.example.com/health',
        'source' => 'manual',
        'current_status' => 'danger',
        'stale_at' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();

    expect($monitor->current_status)->toBe('healthy')
        ->and($monitor->stale_at)->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy'
            && $mail->summary === 'API check succeeded with HTTP status 200.';
    });
});

test('command does not notify when a manual api monitor remains in the same failing status', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'manual-health',
        'url' => 'https://api.example.com/health',
        'source' => 'manual',
        'current_status' => 'warning',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();

    expect($monitor->current_status)->toBe('warning');

    Mail::assertNothingSent();
});
