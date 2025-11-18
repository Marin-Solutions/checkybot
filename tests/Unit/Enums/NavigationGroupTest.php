<?php

namespace Tests\Unit\Enums;

use App\Enums\NavigationGroup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NavigationGroupTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = NavigationGroup::cases();

        $this->assertCount(9, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = NavigationGroup::cases();

        $this->assertContains(NavigationGroup::Operations, $cases);
        $this->assertContains(NavigationGroup::SEO, $cases);
        $this->assertContains(NavigationGroup::Settings, $cases);
        $this->assertContains(NavigationGroup::Monitoring, $cases);
        $this->assertContains(NavigationGroup::Backup, $cases);
        $this->assertContains(NavigationGroup::Notifications, $cases);
        $this->assertContains(NavigationGroup::API, $cases);
        $this->assertContains(NavigationGroup::Server, $cases);
        $this->assertContains(NavigationGroup::BackupManager, $cases);
    }

    #[Test]
    public function it_has_correct_values_for_all_cases(): void
    {
        $this->assertEquals('Operations', NavigationGroup::Operations->value);
        $this->assertEquals('SEO', NavigationGroup::SEO->value);
        $this->assertEquals('Settings', NavigationGroup::Settings->value);
        $this->assertEquals('Monitoring', NavigationGroup::Monitoring->value);
        $this->assertEquals('Backup', NavigationGroup::Backup->value);
        $this->assertEquals('Notifications', NavigationGroup::Notifications->value);
        $this->assertEquals('API', NavigationGroup::API->value);
        $this->assertEquals('Server', NavigationGroup::Server->value);
        $this->assertEquals('Backup Manager', NavigationGroup::BackupManager->value);
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('Operations', (string) NavigationGroup::Operations->value);
        $this->assertEquals('SEO', (string) NavigationGroup::SEO->value);
        $this->assertEquals('Monitoring', (string) NavigationGroup::Monitoring->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(NavigationGroup::Operations, NavigationGroup::from('Operations'));
        $this->assertEquals(NavigationGroup::SEO, NavigationGroup::from('SEO'));
        $this->assertEquals(NavigationGroup::Settings, NavigationGroup::from('Settings'));
        $this->assertEquals(NavigationGroup::Monitoring, NavigationGroup::from('Monitoring'));
        $this->assertEquals(NavigationGroup::Backup, NavigationGroup::from('Backup'));
        $this->assertEquals(NavigationGroup::Notifications, NavigationGroup::from('Notifications'));
        $this->assertEquals(NavigationGroup::API, NavigationGroup::from('API'));
        $this->assertEquals(NavigationGroup::Server, NavigationGroup::from('Server'));
        $this->assertEquals(NavigationGroup::BackupManager, NavigationGroup::from('Backup Manager'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(NavigationGroup::tryFrom('InvalidGroup'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $operations1 = NavigationGroup::Operations;
        $operations2 = NavigationGroup::Operations;
        $seo = NavigationGroup::SEO;

        $this->assertTrue($operations1 === $operations2);
        $this->assertFalse($operations1 === $seo);
    }
}
