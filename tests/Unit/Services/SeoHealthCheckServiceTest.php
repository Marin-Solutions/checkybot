<?php

namespace Tests\Unit\Services;

use App\Models\SeoCheck;
use App\Models\Website;
use App\Services\SeoHealthCheckService;
use Tests\TestCase;

class SeoHealthCheckServiceTest extends TestCase
{
    protected SeoHealthCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SeoHealthCheckService::class);
    }

    public function test_start_manual_check_creates_seo_check_record(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        $check = $this->service->startManualCheck($website);

        $this->assertInstanceOf(SeoCheck::class, $check);
        $this->assertEquals($website->id, $check->website_id);
        $this->assertEquals('pending', $check->status);
    }

    public function test_start_manual_check_dispatches_job(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        $this->service->startManualCheck($website);

        Queue::assertPushed(\App\Jobs\SeoHealthCheckJob::class);
    }

    public function test_cannot_start_check_if_already_running(): void
    {
        $website = Website::factory()->create();

        SeoCheck::factory()->running()->create([
            'website_id' => $website->id,
        ]);

        $this->expectException(\Exception::class);

        $this->service->startManualCheck($website);
    }

    public function test_check_initializes_with_zero_progress(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        $check = $this->service->startManualCheck($website);

        $this->assertEquals(0, $check->progress);
        $this->assertEquals(0, $check->total_urls_crawled);
    }
}
