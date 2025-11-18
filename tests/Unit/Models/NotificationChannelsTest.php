<?php

namespace Tests\Unit\Models;

use App\Models\NotificationChannels;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    public function test_notification_channel_has_fillable_attributes(): void
    {
        $channel = NotificationChannels::factory()->create([
            'title' => 'Test Webhook',
            'method' => 'POST',
            'url' => 'https://example.com/webhook',
            'description' => 'Test description',
        ]);

        $this->assertEquals('Test Webhook', $channel->title);
        $this->assertEquals('POST', $channel->method);
        $this->assertEquals('https://example.com/webhook', $channel->url);
        $this->assertEquals('Test description', $channel->description);
    }

    public function test_notification_channel_casts_request_body_to_array(): void
    {
        $channel = NotificationChannels::factory()->create([
            'request_body' => ['message' => '{message}'],
        ]);

        $this->assertIsArray($channel->request_body);
        $this->assertEquals(['message' => '{message}'], $channel->request_body);
    }

    public function test_notification_channel_can_be_slack(): void
    {
        $channel = NotificationChannels::factory()->slack()->create();

        $this->assertEquals('Slack Webhook', $channel->title);
        $this->assertStringContainsString('slack.com', $channel->url);
        $this->assertArrayHasKey('text', $channel->request_body);
    }

    public function test_notification_channel_can_be_discord(): void
    {
        $channel = NotificationChannels::factory()->discord()->create();

        $this->assertEquals('Discord Webhook', $channel->title);
        $this->assertStringContainsString('discord.com', $channel->url);
        $this->assertArrayHasKey('content', $channel->request_body);
    }

    public function test_test_webhook_sends_get_request(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = NotificationChannels::testWebhook([
            'method' => 'get',
            'url' => 'https://example.com/webhook',
            'description' => 'Test webhook',
        ]);

        $this->assertEquals(200, $result['code']);
        $this->assertEquals(['status' => 'ok'], $result['body']);
    }

    public function test_test_webhook_sends_post_request(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $result = NotificationChannels::testWebhook([
            'method' => 'post',
            'url' => 'https://example.com/webhook',
            'description' => 'Test webhook',
            'request_body' => ['message' => '{message}'],
        ]);

        $this->assertEquals(200, $result['code']);
        $this->assertEquals(['success' => true], $result['body']);
    }

    public function test_test_webhook_replaces_message_placeholder_in_url(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        NotificationChannels::testWebhook([
            'method' => 'get',
            'url' => 'https://example.com/webhook?text={message}',
            'description' => 'Test',
        ]);

        Http::assertSent(function ($request) {
            return ! str_contains($request->url(), '{message}');
        });
    }

    public function test_test_webhook_replaces_description_placeholder_in_url(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        NotificationChannels::testWebhook([
            'method' => 'get',
            'url' => 'https://example.com/webhook?desc={description}',
            'description' => 'My Description',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'My%20Description');
        });
    }

    public function test_test_webhook_replaces_placeholders_in_request_body(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        NotificationChannels::testWebhook([
            'method' => 'post',
            'url' => 'https://example.com/webhook',
            'description' => 'Test Description',
            'request_body' => [
                'msg' => '{message}',
                'desc' => '{description}',
            ],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! str_contains($body['msg'] ?? '', '{message}')
                && ! str_contains($body['desc'] ?? '', '{description}');
        });
    }

    public function test_send_webhook_notification_sends_request(): void
    {
        Http::fake(['*' => Http::response(['result' => 'sent'], 200)]);

        $channel = NotificationChannels::factory()->create([
            'url' => 'https://example.com/webhook',
            'method' => 'POST',
            'request_body' => ['message' => '{message}'],
        ]);

        $result = $channel->sendWebhookNotification([
            'message' => 'Test notification',
            'description' => 'Test description',
        ]);

        $this->assertEquals(200, $result['code']);
        $this->assertEquals(['result' => 'sent'], $result['body']);
    }

    public function test_send_webhook_notification_replaces_placeholders(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $channel = NotificationChannels::factory()->create([
            'url' => 'https://example.com/webhook?text={message}',
            'method' => 'POST',
            'request_body' => ['description' => '{description}'],
        ]);

        $channel->sendWebhookNotification([
            'message' => 'Alert!',
            'description' => 'Server is down',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'Alert')
                && ! str_contains($request->url(), '{message}');
        });
    }

    public function test_send_webhook_notification_handles_errors(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $channel = NotificationChannels::factory()->create();

        $result = $channel->sendWebhookNotification([
            'message' => 'Test',
        ]);

        $this->assertEquals(0, $result['code']);
    }
}
