<?php

namespace Tests\Unit\Models;

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use Tests\TestCase;

class SeoIssueTest extends TestCase
{
    public function test_seo_issue_belongs_to_seo_check(): void
    {
        $check = SeoCheck::factory()->create();
        $issue = SeoIssue::factory()->create(['seo_check_id' => $check->id]);

        $this->assertInstanceOf(SeoCheck::class, $issue->seoCheck);
        $this->assertEquals($check->id, $issue->seoCheck->id);
    }

    public function test_seo_issue_belongs_to_seo_crawl_result(): void
    {
        $crawlResult = SeoCrawlResult::factory()->create();
        $issue = SeoIssue::factory()->create(['seo_crawl_result_id' => $crawlResult->id]);

        $this->assertInstanceOf(SeoCrawlResult::class, $issue->seoCrawlResult);
        $this->assertEquals($crawlResult->id, $issue->seoCrawlResult->id);
    }

    public function test_seo_issue_can_have_error_severity(): void
    {
        $issue = SeoIssue::factory()->error()->create();

        $this->assertEquals(SeoIssueSeverity::Error, $issue->severity);
    }

    public function test_seo_issue_can_have_warning_severity(): void
    {
        $issue = SeoIssue::factory()->warning()->create();

        $this->assertEquals(SeoIssueSeverity::Warning, $issue->severity);
    }

    public function test_seo_issue_can_have_notice_severity(): void
    {
        $issue = SeoIssue::factory()->notice()->create();

        $this->assertEquals(SeoIssueSeverity::Notice, $issue->severity);
    }

    public function test_seo_issue_has_type_field(): void
    {
        $issue = SeoIssue::factory()->create(['type' => 'missing_title']);

        $this->assertEquals('missing_title', $issue->type);
    }

    public function test_seo_issue_has_url_field(): void
    {
        $url = 'https://example.com/page';
        $issue = SeoIssue::factory()->create(['url' => $url]);

        $this->assertEquals($url, $issue->url);
    }

    public function test_seo_issue_has_title_and_description(): void
    {
        $issue = SeoIssue::factory()->create([
            'title' => 'Missing Page Title',
            'description' => 'This page does not have a title tag',
        ]);

        $this->assertEquals('Missing Page Title', $issue->title);
        $this->assertEquals('This page does not have a title tag', $issue->description);
    }

    public function test_seo_issue_can_store_additional_data_as_json(): void
    {
        $data = [
            'expected' => 'Some value',
            'actual' => 'Different value',
        ];

        $issue = SeoIssue::factory()->create(['data' => json_encode($data)]);

        $this->assertEquals($data, json_decode($issue->data, true));
    }
}
