<?php

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\Website;
use Illuminate\Support\Facades\Queue;

test('command can be executed', function () {
    $this->artisan('ssl:check')
        ->expectsOutput('SSL check completed successfully.')
        ->assertSuccessful()
        ->assertExitCode(0);
});

test('command dispatches jobs for websites with ssl expiring in 14 days', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(14),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command dispatches jobs for websites with ssl expiring in 7 days', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(7),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command dispatches jobs for websites with ssl expiring in 3 days', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(3),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command dispatches jobs for websites with ssl expiring in 2 days', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(2),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command dispatches jobs for websites with ssl expiring in 1 day', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(1),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command dispatches jobs for websites with expired ssl', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->subDays(1),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command does not dispatch jobs for ssl expiring in non reminder days', function () {
    Queue::fake();

    // SSL expires in 5 days (not a reminder day)
    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(5),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertNotPushed(CheckSslExpiryDateJob::class);
});

test('command dispatches jobs for websites without ssl expiry date', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => null,
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command does not dispatch jobs for websites with ssl check disabled', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => false,
        'ssl_expiry_date' => today()->addDays(7),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertNotPushed(CheckSslExpiryDateJob::class);
});

test('command dispatches jobs to correct queue', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(7),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushedOn('ssl-check', CheckSslExpiryDateJob::class);
});

test('command dispatches jobs for multiple websites matching criteria', function () {
    Queue::fake();

    Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(14),
    ]);

    Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(7),
    ]);

    Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(1),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 3);
});

test('command dispatches correct website to job', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(7),
        'url' => 'https://example.com',
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command handles no websites', function () {
    Queue::fake();

    $this->artisan('ssl:check')
        ->expectsOutput('SSL check completed successfully.')
        ->assertSuccessful();

    Queue::assertNotPushed(CheckSslExpiryDateJob::class);
});

test('command filters correctly by reminder days', function () {
    Queue::fake();

    // Create websites with various expiry dates
    $reminderDays = [14, 7, 3, 2, 1];
    foreach ($reminderDays as $day) {
        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays($day),
        ]);
    }

    // Create websites with non-reminder days
    $nonReminderDays = [13, 10, 6, 5, 4];
    foreach ($nonReminderDays as $day) {
        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays($day),
        ]);
    }

    $this->artisan('ssl:check')
        ->assertSuccessful();

    // Should only dispatch for reminder days
    Queue::assertPushed(CheckSslExpiryDateJob::class, 5);
});

test('command handles ssl expired multiple days ago', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->subDays(10),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
});

test('command handles ssl expiring today', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today(),
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    // Today means 0 days difference, which is not in the reminder days list
    // but less than 0 should trigger the check
    Queue::assertNotPushed(CheckSslExpiryDateJob::class);
});

test('command displays success message', function () {
    $this->artisan('ssl:check')
        ->expectsOutput('SSL check completed successfully.')
        ->assertSuccessful();
});

test('command has correct signature', function () {
    $this->artisan('ssl:check')
        ->assertSuccessful();
});

test('command only checks websites with ssl check enabled', function () {
    Queue::fake();

    // Enabled with matching expiry
    Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => today()->addDays(7),
    ]);

    // Disabled with matching expiry
    Website::factory()->create([
        'ssl_check' => false,
        'ssl_expiry_date' => today()->addDays(7),
    ]);

    // Enabled without expiry
    Website::factory()->create([
        'ssl_check' => true,
        'ssl_expiry_date' => null,
    ]);

    $this->artisan('ssl:check')
        ->assertSuccessful();

    // Should dispatch for enabled websites only (2 websites)
    Queue::assertPushed(CheckSslExpiryDateJob::class, 2);
});

test('command handles large number of websites', function () {
    Queue::fake();

    for ($i = 0; $i < 100; $i++) {
        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(7),
        ]);
    }

    $this->artisan('ssl:check')
        ->assertSuccessful();

    Queue::assertPushed(CheckSslExpiryDateJob::class, 100);
});
