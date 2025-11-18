<?php

namespace Tests\Unit\Enums;

use App\Enums\WebsiteServicesEnum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebsiteServicesEnumTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = WebsiteServicesEnum::cases();

        $this->assertCount(3, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = WebsiteServicesEnum::cases();

        $this->assertContains(WebsiteServicesEnum::WEBSITE_CHECK, $cases);
        $this->assertContains(WebsiteServicesEnum::API_MONITOR, $cases);
        $this->assertContains(WebsiteServicesEnum::ALL_CHECK, $cases);
    }

    #[Test]
    public function it_has_correct_values_for_all_cases(): void
    {
        $this->assertEquals('WEBSITE_CHECK', WebsiteServicesEnum::WEBSITE_CHECK->value);
        $this->assertEquals('API_MONITOR', WebsiteServicesEnum::API_MONITOR->value);
        $this->assertEquals('ALL_CHECK', WebsiteServicesEnum::ALL_CHECK->value);
    }

    #[Test]
    public function it_returns_correct_labels(): void
    {
        $this->assertEquals('Website Check', WebsiteServicesEnum::WEBSITE_CHECK->label());
        $this->assertEquals('API Monitor', WebsiteServicesEnum::API_MONITOR->label());
        $this->assertEquals('All Check', WebsiteServicesEnum::ALL_CHECK->label());
    }

    #[Test]
    public function keys_method_returns_array_of_case_names(): void
    {
        $keys = WebsiteServicesEnum::keys();

        $this->assertIsArray($keys);
        $this->assertCount(3, $keys);
        $this->assertContains('WEBSITE_CHECK', $keys);
        $this->assertContains('API_MONITOR', $keys);
        $this->assertContains('ALL_CHECK', $keys);
    }

    #[Test]
    public function to_array_returns_associative_array_of_values_and_labels(): void
    {
        $array = WebsiteServicesEnum::toArray();

        $this->assertIsArray($array);
        $this->assertCount(3, $array);
        $this->assertEquals([
            'WEBSITE_CHECK' => 'Website Check',
            'API_MONITOR' => 'API Monitor',
            'ALL_CHECK' => 'All Check',
        ], $array);
    }

    #[Test]
    public function to_array_keys_match_enum_values(): void
    {
        $array = WebsiteServicesEnum::toArray();
        $keys = array_keys($array);

        foreach (WebsiteServicesEnum::cases() as $case) {
            $this->assertContains($case->value, $keys);
        }
    }

    #[Test]
    public function to_array_values_match_labels(): void
    {
        $array = WebsiteServicesEnum::toArray();

        foreach (WebsiteServicesEnum::cases() as $case) {
            $this->assertEquals($case->label(), $array[$case->value]);
        }
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('WEBSITE_CHECK', (string) WebsiteServicesEnum::WEBSITE_CHECK->value);
        $this->assertEquals('API_MONITOR', (string) WebsiteServicesEnum::API_MONITOR->value);
        $this->assertEquals('ALL_CHECK', (string) WebsiteServicesEnum::ALL_CHECK->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(WebsiteServicesEnum::WEBSITE_CHECK, WebsiteServicesEnum::from('WEBSITE_CHECK'));
        $this->assertEquals(WebsiteServicesEnum::API_MONITOR, WebsiteServicesEnum::from('API_MONITOR'));
        $this->assertEquals(WebsiteServicesEnum::ALL_CHECK, WebsiteServicesEnum::from('ALL_CHECK'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(WebsiteServicesEnum::tryFrom('INVALID_CHECK'));
        $this->assertNull(WebsiteServicesEnum::tryFrom('SERVER_MONITOR'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $websiteCheck1 = WebsiteServicesEnum::WEBSITE_CHECK;
        $websiteCheck2 = WebsiteServicesEnum::WEBSITE_CHECK;
        $apiMonitor = WebsiteServicesEnum::API_MONITOR;

        $this->assertTrue($websiteCheck1 === $websiteCheck2);
        $this->assertFalse($websiteCheck1 === $apiMonitor);
    }
}
