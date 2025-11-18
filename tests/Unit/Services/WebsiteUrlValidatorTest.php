<?php

namespace Tests\Unit\Services;

use App\Models\Website;
use App\Services\WebsiteUrlValidator;
use Filament\Notifications\Notification;
use Tests\TestCase;

class WebsiteUrlValidatorTest extends TestCase
{
    public function test_validates_successfully_for_valid_url(): void
    {
        $url = 'https://example.com';
        $haltCalled = false;

        Website::shouldReceive('whereUrl')
            ->with($url)
            ->andReturnSelf()
            ->once();

        Website::shouldReceive('count')
            ->andReturn(0)
            ->once();

        Website::shouldReceive('checkWebsiteExists')
            ->with($url)
            ->andReturn(true)
            ->once();

        Website::shouldReceive('checkResponseCode')
            ->with($url)
            ->andReturn(['code' => 200, 'body' => ''])
            ->once();

        WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
            $haltCalled = true;
        });

        $this->assertFalse($haltCalled);
    }

    public function test_halts_when_url_exists_in_database(): void
    {
        $url = 'https://example.com';
        $haltCalled = false;

        Website::shouldReceive('whereUrl')
            ->with($url)
            ->andReturnSelf()
            ->once();

        Website::shouldReceive('count')
            ->andReturn(1)
            ->once();

        Website::shouldReceive('checkWebsiteExists')
            ->with($url)
            ->andReturn(true)
            ->once();

        Website::shouldReceive('checkResponseCode')
            ->with($url)
            ->andReturn(['code' => 200, 'body' => ''])
            ->once();

        Notification::fake();

        WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
            $haltCalled = true;
        });

        $this->assertTrue($haltCalled);
        Notification::assertSentTo(
            [],
            function (Notification $notification) {
                return $notification->getTitle() === __('URL Website Exists in database');
            }
        );
    }

    public function test_halts_when_website_not_exists_in_dns(): void
    {
        $url = 'https://nonexistent.example';
        $haltCalled = false;

        Website::shouldReceive('whereUrl')
            ->with($url)
            ->andReturnSelf()
            ->once();

        Website::shouldReceive('count')
            ->andReturn(0)
            ->once();

        Website::shouldReceive('checkWebsiteExists')
            ->with($url)
            ->andReturn(false)
            ->once();

        Website::shouldReceive('checkResponseCode')
            ->with($url)
            ->andReturn(['code' => 200, 'body' => ''])
            ->once();

        Notification::fake();

        WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
            $haltCalled = true;
        });

        $this->assertTrue($haltCalled);
        Notification::assertSentTo(
            [],
            function (Notification $notification) {
                return $notification->getTitle() === __('website was not registered');
            }
        );
    }

    public function test_halts_on_certificate_error(): void
    {
        $url = 'https://example.com';
        $haltCalled = false;

        Website::shouldReceive('whereUrl')
            ->with($url)
            ->andReturnSelf()
            ->once();

        Website::shouldReceive('count')
            ->andReturn(0)
            ->once();

        Website::shouldReceive('checkWebsiteExists')
            ->with($url)
            ->andReturn(true)
            ->once();

        Website::shouldReceive('checkResponseCode')
            ->with($url)
            ->andReturn(['code' => 60, 'body' => 'SSL certificate problem'])
            ->once();

        Notification::fake();

        WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
            $haltCalled = true;
        });

        $this->assertTrue($haltCalled);
        Notification::assertSentTo(
            [],
            function (Notification $notification) {
                return $notification->getTitle() === __('URL website, problem with certificate');
            }
        );
    }

    public function test_halts_on_non_200_response(): void
    {
        $url = 'https://example.com';
        $haltCalled = false;

        Website::shouldReceive('whereUrl')
            ->with($url)
            ->andReturnSelf()
            ->once();

        Website::shouldReceive('count')
            ->andReturn(0)
            ->once();

        Website::shouldReceive('checkWebsiteExists')
            ->with($url)
            ->andReturn(true)
            ->once();

        Website::shouldReceive('checkResponseCode')
            ->with($url)
            ->andReturn(['code' => 1, 'body' => 1])
            ->once();

        Notification::fake();

        WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
            $haltCalled = true;
        });

        $this->assertTrue($haltCalled);
        Notification::assertSentTo(
            [],
            function (Notification $notification) {
                return $notification->getTitle() === __('URL Website Response error');
            }
        );
    }

    public function test_halts_on_unknown_error(): void
    {
        $url = 'https://example.com';
        $haltCalled = false;

        Website::shouldReceive('whereUrl')
            ->with($url)
            ->andReturnSelf()
            ->once();

        Website::shouldReceive('count')
            ->andReturn(0)
            ->once();

        Website::shouldReceive('checkWebsiteExists')
            ->with($url)
            ->andReturn(true)
            ->once();

        Website::shouldReceive('checkResponseCode')
            ->with($url)
            ->andReturn(['code' => 99, 'body' => 'Unknown error message'])
            ->once();

        Notification::fake();

        WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
            $haltCalled = true;
        });

        $this->assertTrue($haltCalled);
        Notification::assertSentTo(
            [],
            function (Notification $notification) {
                return $notification->getTitle() === __('URL website a unknown error. try other url');
            }
        );
    }
}
