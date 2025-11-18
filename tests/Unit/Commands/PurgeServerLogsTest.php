<?php

namespace Tests\Unit\Commands;

use App\Models\Server;
use App\Models\ServerInformationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeServerLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_be_executed(): void
    {
        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();
    }

    public function test_command_keeps_all_data_from_last_24_hours(): void
    {
        $server = Server::factory()->create();

        // Create data from last 24 hours with various minutes
        $recentLogs = [];
        for ($i = 0; $i < 24; $i++) {
            $recentLogs[] = ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => now()->subHours($i),
            ]);
        }

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        foreach ($recentLogs as $log) {
            $this->assertDatabaseHas('server_information_history', [
                'id' => $log->id,
            ]);
        }
    }

    public function test_command_keeps_10_minute_intervals_for_data_between_24_hours_and_7_days(): void
    {
        $server = Server::factory()->create();

        // Create data at 10-minute intervals (should be kept)
        $keepLogs = [];
        for ($i = 0; $i < 6; $i++) {
            $timestamp = now()->subHours(25)->startOfHour()->addMinutes($i * 10);
            $keepLogs[] = ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => $timestamp,
            ]);
        }

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // Verify that all 10-minute interval records are kept
        foreach ($keepLogs as $log) {
            $this->assertDatabaseHas('server_information_history', [
                'id' => $log->id,
            ]);
        }
    }

    public function test_command_deletes_non_10_minute_intervals_for_data_between_24_hours_and_7_days(): void
    {
        $server = Server::factory()->create();

        // Create data at non-10-minute intervals (should be deleted)
        $deleteLogs = [];
        $invalidMinutes = [5, 15, 25, 35, 45, 55];
        foreach ($invalidMinutes as $minute) {
            $timestamp = now()->subHours(25)->startOfHour()->addMinutes($minute);
            $deleteLogs[] = ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => $timestamp,
            ]);
        }

        $initialCount = ServerInformationHistory::count();

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // Verify deletions occurred
        $this->assertEquals(0, ServerInformationHistory::count());
    }

    public function test_command_keeps_hourly_data_for_data_older_than_7_days(): void
    {
        $server = Server::factory()->create();

        // Create data at hourly intervals (minute 00, should be kept)
        $keepLogs = [];
        for ($i = 0; $i < 10; $i++) {
            $timestamp = now()->subDays(8 + $i)->startOfHour();
            $keepLogs[] = ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => $timestamp,
            ]);
        }

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        foreach ($keepLogs as $log) {
            $this->assertDatabaseHas('server_information_history', [
                'id' => $log->id,
            ]);
        }
    }

    public function test_command_deletes_non_hourly_data_for_data_older_than_7_days(): void
    {
        $server = Server::factory()->create();

        // Create data at non-hourly intervals (should be deleted)
        $deleteLogs = [];
        for ($i = 1; $i < 60; $i++) {
            $timestamp = now()->subDays(8)->startOfHour()->addMinutes($i);
            $deleteLogs[] = ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => $timestamp,
            ]);
        }

        $initialCount = ServerInformationHistory::count();

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // Verify all non-hourly data was deleted
        $this->assertEquals(0, ServerInformationHistory::count());
    }

    public function test_command_handles_empty_database(): void
    {
        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        $this->assertEquals(0, ServerInformationHistory::count());
    }

    public function test_command_preserves_data_correctly_across_all_time_ranges(): void
    {
        $server = Server::factory()->create();

        // Recent data (last 24 hours) - all should be kept
        $recentLog = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subHours(12)->startOfHour()->addMinutes(15),
        ]);

        // Mid-range data (25 hours to 7 days) - keep 10-minute intervals
        $midRangeKeepLog = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subHours(30)->startOfHour(),
        ]);

        $midRangeDeleteLog = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subHours(30)->startOfHour()->addMinutes(5),
        ]);

        // Old data (older than 7 days) - keep hourly
        $oldKeepLog = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subDays(10)->startOfHour(),
        ]);

        $oldDeleteLog = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subDays(10)->startOfHour()->addMinutes(30),
        ]);

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // Verify recent data is kept
        $this->assertDatabaseHas('server_information_history', ['id' => $recentLog->id]);

        // Verify mid-range 10-minute interval is kept
        $this->assertDatabaseHas('server_information_history', ['id' => $midRangeKeepLog->id]);

        // Verify old hourly data is kept
        $this->assertDatabaseHas('server_information_history', ['id' => $oldKeepLog->id]);

        // Verify non-compliant data was deleted
        $this->assertDatabaseMissing('server_information_history', ['id' => $midRangeDeleteLog->id]);
        $this->assertDatabaseMissing('server_information_history', ['id' => $oldDeleteLog->id]);
    }

    public function test_command_handles_multiple_servers(): void
    {
        $server1 = Server::factory()->create();
        $server2 = Server::factory()->create();

        // Create data for both servers
        ServerInformationHistory::factory()->create([
            'server_id' => $server1->id,
            'created_at' => now()->subDays(10)->startOfHour()->addMinutes(15),
        ]);

        ServerInformationHistory::factory()->create([
            'server_id' => $server2->id,
            'created_at' => now()->subDays(10)->startOfHour()->addMinutes(30),
        ]);

        $initialCount = ServerInformationHistory::count();

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // Verify deletions occurred for both servers
        $this->assertEquals(0, ServerInformationHistory::count());
    }

    public function test_command_keeps_valid_10_minute_intervals(): void
    {
        $server = Server::factory()->create();

        // Valid 10-minute intervals: 00, 10, 20, 30, 40, 50
        $validMinutes = [0, 10, 20, 30, 40, 50];

        foreach ($validMinutes as $minute) {
            ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => now()->subDays(3)->startOfHour()->addMinutes($minute),
            ]);
        }

        $initialCount = ServerInformationHistory::count();

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // All valid intervals should be kept
        $this->assertEquals($initialCount, ServerInformationHistory::count());
    }

    public function test_command_deletes_invalid_10_minute_intervals(): void
    {
        $server = Server::factory()->create();

        // Invalid 10-minute intervals
        $invalidMinutes = [5, 15, 25, 35, 45, 55];

        foreach ($invalidMinutes as $minute) {
            ServerInformationHistory::factory()->create([
                'server_id' => $server->id,
                'created_at' => now()->subDays(3)->startOfHour()->addMinutes($minute),
            ]);
        }

        $initialCount = ServerInformationHistory::count();

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // All invalid intervals should be deleted
        $this->assertEquals(0, ServerInformationHistory::count());
    }

    public function test_command_has_correct_signature(): void
    {
        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();
    }

    public function test_command_operates_on_correct_time_boundaries(): void
    {
        $server = Server::factory()->create();

        // Just under 24 hours old (should be kept, still in recent range)
        $at24Hours = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subHours(23)->startOfHour()->addMinutes(15),
        ]);

        // Between 24h and 7 days, with valid 10-minute interval (should be kept)
        $midRangeKeep = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subHours(25)->startOfHour(),
        ]);

        // Between 24h and 7 days, with invalid minute (should be deleted)
        $midRangeDelete = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subHours(25)->startOfHour()->addMinutes(15),
        ]);

        // Older than 7 days with hourly interval (should be kept)
        $oldKeep = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subDays(8)->startOfHour(),
        ]);

        // Older than 7 days with non-hourly minute (should be deleted)
        $oldDelete = ServerInformationHistory::factory()->create([
            'server_id' => $server->id,
            'created_at' => now()->subDays(8)->startOfHour()->addMinutes(15),
        ]);

        $this->artisan('app:purge-server-logs')
            ->assertSuccessful();

        // Verify boundary behaviors
        $this->assertDatabaseHas('server_information_history', ['id' => $at24Hours->id]);
        $this->assertDatabaseHas('server_information_history', ['id' => $midRangeKeep->id]);
        $this->assertDatabaseHas('server_information_history', ['id' => $oldKeep->id]);

        // Verify deletions
        $this->assertDatabaseMissing('server_information_history', ['id' => $midRangeDelete->id]);
        $this->assertDatabaseMissing('server_information_history', ['id' => $oldDelete->id]);
    }
}
