<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Models\ServerRule;
use Tests\TestCase;

class ServerRuleTest extends TestCase
{
    public function test_server_rule_belongs_to_server(): void
    {
        $server = Server::factory()->create();
        $rule = ServerRule::factory()->create(['server_id' => $server->id]);

        $this->assertInstanceOf(Server::class, $rule->server);
        $this->assertEquals($server->id, $rule->server->id);
    }

    public function test_server_rule_has_fillable_attributes(): void
    {
        $rule = ServerRule::factory()->create([
            'metric' => 'cpu_usage',
            'operator' => '>',
            'value' => 80.5,
            'channel' => 'email',
            'is_active' => true,
        ]);

        $this->assertEquals('cpu_usage', $rule->metric);
        $this->assertEquals('>', $rule->operator);
        $this->assertEquals(80.5, $rule->value);
        $this->assertEquals('email', $rule->channel);
        $this->assertTrue($rule->is_active);
    }

    public function test_server_rule_casts_value_to_float(): void
    {
        $rule = ServerRule::factory()->create(['value' => '85']);

        $this->assertIsFloat($rule->value);
        $this->assertEquals(85.0, $rule->value);
    }

    public function test_server_rule_casts_is_active_to_boolean(): void
    {
        $rule = ServerRule::factory()->create(['is_active' => 1]);

        $this->assertIsBool($rule->is_active);
        $this->assertTrue($rule->is_active);
    }

    public function test_server_rule_can_be_inactive(): void
    {
        $rule = ServerRule::factory()->create(['is_active' => false]);

        $this->assertFalse($rule->is_active);
    }

    public function test_server_rule_supports_cpu_usage_metric(): void
    {
        $rule = ServerRule::factory()->cpuUsage()->create();

        $this->assertEquals('cpu_usage', $rule->metric);
        $this->assertEquals('>', $rule->operator);
        $this->assertEquals(80, $rule->value);
    }

    public function test_server_rule_supports_ram_usage_metric(): void
    {
        $rule = ServerRule::factory()->ramUsage()->create();

        $this->assertEquals('ram_usage', $rule->metric);
        $this->assertEquals('>', $rule->operator);
        $this->assertEquals(90, $rule->value);
    }

    public function test_server_rule_supports_disk_usage_metric(): void
    {
        $rule = ServerRule::factory()->diskUsage()->create();

        $this->assertEquals('disk_usage', $rule->metric);
        $this->assertEquals('>', $rule->operator);
        $this->assertEquals(85, $rule->value);
    }

    public function test_server_rule_supports_different_operators(): void
    {
        $greaterThanRule = ServerRule::factory()->create(['operator' => '>']);
        $lessThanRule = ServerRule::factory()->create(['operator' => '<']);
        $equalsRule = ServerRule::factory()->create(['operator' => '=']);

        $this->assertEquals('>', $greaterThanRule->operator);
        $this->assertEquals('<', $lessThanRule->operator);
        $this->assertEquals('=', $equalsRule->operator);
    }

    public function test_server_rule_supports_different_channels(): void
    {
        $emailRule = ServerRule::factory()->create(['channel' => 'email']);
        $webhookRule = ServerRule::factory()->create(['channel' => 'webhook']);

        $this->assertEquals('email', $emailRule->channel);
        $this->assertEquals('webhook', $webhookRule->channel);
    }
}
