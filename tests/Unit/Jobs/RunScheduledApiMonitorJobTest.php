<?php

use App\Jobs\RunScheduledApiMonitorJob;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApiAssertion;
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

test('run scheduled api monitor job records execution exceptions as failed monitor results', function () {
    Log::spy();

    $monitor = MonitorApis::factory()->create([
        'title' => 'unstable-health',
        'url' => 'https://api.example.com/unstable',
        'current_status' => 'healthy',
    ]);

    $executionService = Mockery::mock(ApiMonitorExecutionService::class);
    $executionService
        ->shouldReceive('execute')
        ->once()
        ->andThrow(new RuntimeException('Unexpected monitor runner failure'));

    (new RunScheduledApiMonitorJob($monitor))->handle(
        $executionService,
        app(\App\Services\HealthEventNotificationService::class),
    );

    $monitor->refresh();

    expect($monitor->current_status)->toBe('danger')
        ->and($monitor->status_summary)->toBe('API monitor run failed before completing the scheduled check.');

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'http_code' => 0,
        'status' => 'danger',
        'summary' => 'API monitor run failed before completing the scheduled check.',
        'is_on_demand' => false,
    ]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'Recording failed scheduled API monitor run: Unexpected monitor runner failure',
            Mockery::on(fn (array $context): bool => ($context['monitor_id'] ?? null) === $monitor->id
                && ($context['monitor_title'] ?? null) === 'unstable-health'
                && ($context['exception'] ?? null) === RuntimeException::class)
        );
});

test('run scheduled api monitor job logs queue failure details', function () {
    Log::spy();

    $monitor = MonitorApis::factory()->create([
        'title' => 'queue-timeout-health',
    ]);

    (new RunScheduledApiMonitorJob($monitor))->failed(new RuntimeException('Worker timeout'));

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
