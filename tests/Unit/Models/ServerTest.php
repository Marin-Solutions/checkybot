<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogCategory;
use App\Models\ServerRule;
use App\Models\User;
use Tests\TestCase;

class ServerTest extends TestCase
{
    public function test_server_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['created_by' => $user->id]);

        $this->assertInstanceOf(User::class, $server->user);
        $this->assertEquals($user->id, $server->user->id);
    }

    public function test_server_has_many_information_history(): void
    {
        $server = Server::factory()->create();
        ServerInformationHistory::factory()->count(5)->create(['server_id' => $server->id]);

        $this->assertCount(5, $server->informationHistory);
        $this->assertInstanceOf(ServerInformationHistory::class, $server->informationHistory->first());
    }

    public function test_server_has_many_rules(): void
    {
        $server = Server::factory()->create();
        ServerRule::factory()->count(3)->create(['server_id' => $server->id]);

        $this->assertCount(3, $server->rules);
        $this->assertInstanceOf(ServerRule::class, $server->rules->first());
    }

    public function test_server_has_many_log_categories(): void
    {
        $server = Server::factory()->create();
        ServerLogCategory::factory()->count(2)->create(['server_id' => $server->id]);

        $this->assertCount(2, $server->logCategories);
    }

    public function test_server_requires_name(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Server::factory()->create(['name' => null]);
    }

    public function test_server_requires_ip(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Server::factory()->create(['ip' => null]);
    }

    public function test_server_can_have_ploi_server_id(): void
    {
        $server = Server::factory()->create(['ploi_server_id' => 12345]);

        $this->assertEquals(12345, $server->ploi_server_id);
    }

    public function test_server_has_token_for_authentication(): void
    {
        $server = Server::factory()->create();

        $this->assertNotNull($server->token);
        $this->assertIsString($server->token);
    }

    public function test_server_tracks_cpu_cores(): void
    {
        $server = Server::factory()->create(['cpu_cores' => 8]);

        $this->assertEquals(8, $server->cpu_cores);
    }
}
