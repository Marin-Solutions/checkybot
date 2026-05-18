<?php

use App\Jobs\RunScheduledApiMonitorJob;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Services\ApiMonitorExecutionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('run scheduled api monitor job is unique and queued with enough time for configured retries', function () {
    $monitor = MonitorApis::factory()->create();
    $job = new RunScheduledApiMonitorJob($monitor);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(420)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->uniqueFor)->toBe(480)
        ->and($job->uniqueId())->toBe("api-monitor:{$monitor->id}:scheduled");
});

test('redis queue lease is longer than scheduled api monitor timeout', function () {
    $monitor = MonitorApis::factory()->create();
    $job = new RunScheduledApiMonitorJob($monitor);

    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($job->timeout);
});

test('run scheduled api monitor job records live status and sends transition notifications', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'package-health',
        'url' => 'https://api.example.com/health',
        'source' => 'package',
        'package_name' => 'package-health',
        'current_status' => 'healthy',
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

    (new RunScheduledApiMonitorJob($monitor))->handle(
        app(\App\Services\ApiMonitorExecutionService::class),
        app(\App\Services\HealthEventNotificationService::class),
    );

    $monitor->refresh();

    expect($monitor->current_status)->toBe('warning');

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'status' => 'warning',
        'is_on_demand' => false,
    ]);

    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('run scheduled api monitor job skips monitors disabled after dispatch', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/disabled-after-dispatch',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
    ]);

    $job = new RunScheduledApiMonitorJob($monitor);

    $monitor->update(['is_enabled' => false]);

    $job->handle(
        app(\App\Services\ApiMonitorExecutionService::class),
        app(\App\Services\HealthEventNotificationService::class),
    );

    $monitor->refresh();

    expect($monitor->current_status)->toBe('unknown')
        ->and($monitor->last_heartbeat_at)->toBeNull();

    $this->assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);

    Http::assertNothingSent();
});

test('run scheduled api monitor job uses the scheduled execution budget', function () {
    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/scheduled-budget',
    ]);

    $executionService = Mockery::mock(ApiMonitorExecutionService::class);
    $executionService
        ->shouldReceive('execute')
        ->once()
        ->with(Mockery::on(fn (MonitorApis $value): bool => $value->is($monitor)), false, true)
        ->andReturn([
            'result' => new MonitorApiResult(['http_code' => 200]),
            'status' => 'healthy',
            'summary' => 'API monitor returned HTTP 200.',
            'previous_status' => 'healthy',
        ]);

    (new RunScheduledApiMonitorJob($monitor))->handle(
        $executionService,
        app(\App\Services\HealthEventNotificationService::class),
    );
});

test('run scheduled api monitor job records execution throwables as failed monitor results', function () {
    Log::spy();

    Http::fake(function () {
        throw new Error('Unexpected monitor runner failure token=secret-value');
    });

    $monitor = MonitorApis::factory()->create([
        'title' => 'unstable-health',
        'url' => 'https://api.example.com/unstable',
        'current_status' => 'healthy',
    ]);

    (new RunScheduledApiMonitorJob($monitor))->handle(
        app(\App\Services\ApiMonitorExecutionService::class),
        app(\App\Services\HealthEventNotificationService::class),
    );

    $monitor->refresh();

    expect($monitor->current_status)->toBe('danger')
        ->and($monitor->status_summary)->toBe('API monitor run failed before completing the check.');

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'http_code' => 0,
        'status' => 'danger',
        'summary' => 'API monitor run failed before completing the check.',
        'is_on_demand' => false,
        'transport_error_type' => 'unknown',
    ]);

    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($result->transport_error_message)->toBe('Unexpected monitor runner failure token=[redacted]')
        ->and($result->response_body[MonitorApiResult::ERROR_METADATA_KEY] ?? null)->toBe('[redacted]');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'Recording failed API monitor execution as check evidence.',
            Mockery::on(fn (array $context): bool => ($context['monitor_id'] ?? null) === $monitor->id
                && ($context['monitor_title'] ?? null) === 'unstable-health'
                && ($context['exception'] ?? null) === Error::class)
        );
});

test('run scheduled api monitor job records queue failures as failed monitor results', function () {
    Log::spy();
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'queue-timeout-health',
        'current_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    (new RunScheduledApiMonitorJob($monitor))->failed(new RuntimeException('Worker timeout'));

    $monitor->refresh();

    expect($monitor->current_status)->toBe('danger')
        ->and($monitor->status_summary)->toBe('API monitor run failed before the scheduled check could complete.');

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'http_code' => 0,
        'status' => 'danger',
        'summary' => 'API monitor run failed before the scheduled check could complete.',
        'is_on_demand' => false,
        'transport_error_type' => 'unknown',
        'transport_error_message' => 'Worker timeout',
    ]);

    Mail::assertSent(HealthStatusAlert::class, 1);

    Log::shouldHaveReceived('error')
        ->once()
        ->with(
            'Scheduled API monitor job failed before a controlled result could be recorded.',
            Mockery::on(fn (array $context): bool => ($context['monitor_id'] ?? null) === $monitor->id
                && ($context['monitor_title'] ?? null) === 'queue-timeout-health'
                && ($context['exception'] ?? null) === RuntimeException::class
                && ($context['message'] ?? null) === 'Worker timeout')
        );
});

test('run scheduled api monitor job failure hook does not duplicate an already recorded scheduled result', function () {
    $monitor = MonitorApis::factory()->create([
        'title' => 'already-recorded-health',
        'current_status' => 'healthy',
    ]);

    $job = new RunScheduledApiMonitorJob($monitor);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'http_code' => 0,
        'status' => 'danger',
        'summary' => 'API monitor run failed before completing the check.',
        'transport_error_type' => 'unknown',
        'transport_error_message' => 'Existing controlled failure',
        'created_at' => now()->addSecond(),
    ]);

    $job->failed(new RuntimeException('Worker timeout after result'));

    expect(MonitorApiResult::where('monitor_api_id', $monitor->id)->count())->toBe(1);

    $this->assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'transport_error_message' => 'Worker timeout after result',
    ]);
});
