<?php

namespace Tests\Unit\Models;

use App\Models\OutboundLink;
use Tests\TestCase;

class OutboundLinkTest extends TestCase
{
    public function test_outbound_link_has_correct_table_name(): void
    {
        $link = new OutboundLink;

        $this->assertEquals('outbound_link', $link->getTable());
    }

    public function test_outbound_link_has_fillable_attributes(): void
    {
        $link = OutboundLink::create([
            'website_id' => 1,
            'found_on' => 'https://example.com/page',
            'outgoing_url' => 'https://external.com',
            'http_status_code' => 200,
            'last_checked_at' => now(),
        ]);

        $this->assertEquals(1, $link->website_id);
        $this->assertEquals('https://example.com/page', $link->found_on);
        $this->assertEquals('https://external.com', $link->outgoing_url);
        $this->assertEquals(200, $link->http_status_code);
        $this->assertNotNull($link->last_checked_at);
    }

    public function test_outbound_link_tracks_found_and_outgoing_urls(): void
    {
        $link = OutboundLink::create([
            'website_id' => 1,
            'found_on' => 'https://mysite.com/blog/post',
            'outgoing_url' => 'https://partner-site.com',
            'http_status_code' => 200,
            'last_checked_at' => now(),
        ]);

        $this->assertStringContainsString('mysite.com', $link->found_on);
        $this->assertStringContainsString('partner-site.com', $link->outgoing_url);
    }

    public function test_outbound_link_records_http_status_code(): void
    {
        $successLink = OutboundLink::create([
            'website_id' => 1,
            'found_url' => 'https://example.com',
            'outgoing_url' => 'https://external.com',
            'http_status_code' => 200,
            'last_checked_at' => now(),
        ]);

        $errorLink = OutboundLink::create([
            'website_id' => 1,
            'found_url' => 'https://example.com',
            'outgoing_url' => 'https://broken.com',
            'http_status_code' => 404,
            'last_checked_at' => now(),
        ]);

        $this->assertEquals(200, $successLink->http_status_code);
        $this->assertEquals(404, $errorLink->http_status_code);
    }

    public function test_outbound_link_tracks_last_checked_timestamp(): void
    {
        $checkedTime = now()->subHours(2);
        $link = OutboundLink::create([
            'website_id' => 1,
            'found_url' => 'https://example.com',
            'outgoing_url' => 'https://external.com',
            'http_status_code' => 200,
            'last_checked_at' => $checkedTime,
        ]);

        $this->assertEquals($checkedTime->format('Y-m-d H:i'), $link->last_checked_at->format('Y-m-d H:i'));
    }
}
