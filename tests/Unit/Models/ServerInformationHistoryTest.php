<?php

namespace Tests\Unit\Models;

use App\Models\ServerInformationHistory;
use Tests\TestCase;

class ServerInformationHistoryTest extends TestCase
{
    public function test_server_information_history_has_fillable_attributes(): void
    {
        $history = ServerInformationHistory::factory()->create([
            'cpu_load' => 2.5,
            'ram_free_percentage' => 45.5,
            'ram_free' => 4096,
            'disk_free_percentage' => 60.0,
            'disk_free_bytes' => 102400,
        ]);

        $this->assertNotNull($history->server_id);
        $this->assertEquals(2.5, $history->cpu_load);
        $this->assertEquals(45.5, $history->ram_free_percentage);
        $this->assertEquals(4096, $history->ram_free);
        $this->assertEquals(60.0, $history->disk_free_percentage);
        $this->assertEquals(102400, $history->disk_free_bytes);
    }

    public function test_server_information_history_casts_dates(): void
    {
        $history = ServerInformationHistory::factory()->create();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $history->created_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $history->updated_at);
    }

    public function test_server_information_history_uses_correct_table(): void
    {
        $history = new ServerInformationHistory;

        $this->assertEquals('server_information_history', $history->getTable());
    }

    public function test_is_valid_token_returns_true(): void
    {
        $this->assertTrue(ServerInformationHistory::isValidToken());
    }

    public function test_copy_command_generates_wget_command(): void
    {
        $user = $this->actingAsSuperAdmin();
        $serverId = 123;

        $command = ServerInformationHistory::copyCommand($serverId);

        $this->assertStringContainsString('wget', $command);
        $this->assertStringContainsString("reporter/$serverId/{$user->id}", $command);
        $this->assertStringContainsString('reporter_server_info.sh', $command);
        $this->assertStringContainsString('chmod +x', $command);
        $this->assertStringContainsString('crontab', $command);
    }

    public function test_copy_command_includes_cron_setup(): void
    {
        $user = $this->actingAsSuperAdmin();
        $serverId = 456;

        $command = ServerInformationHistory::copyCommand($serverId);

        $this->assertStringContainsString('CRON_CMD=', $command);
        $this->assertStringContainsString('crontab -l', $command);
        $this->assertStringContainsString('*/1 * * * *', $command);
    }
}
