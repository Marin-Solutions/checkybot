<?php

namespace Tests\Unit\Enums;

use App\Enums\NavigationIcon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NavigationIconTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = NavigationIcon::cases();

        $this->assertCount(11, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = NavigationIcon::cases();

        $this->assertContains(NavigationIcon::Key, $cases);
        $this->assertContains(NavigationIcon::GlobeAlt, $cases);
        $this->assertContains(NavigationIcon::MagnifyingGlass, $cases);
        $this->assertContains(NavigationIcon::ServerStack, $cases);
        $this->assertContains(NavigationIcon::ArrowPathRoundedSquare, $cases);
        $this->assertContains(NavigationIcon::BellAlert, $cases);
        $this->assertContains(NavigationIcon::Cog, $cases);
        $this->assertContains(NavigationIcon::RectangleStack, $cases);
        $this->assertContains(NavigationIcon::CloudArrowUp, $cases);
        $this->assertContains(NavigationIcon::InboxStack, $cases);
        $this->assertContains(NavigationIcon::Users, $cases);
    }

    #[Test]
    public function it_has_correct_heroicon_values_for_all_cases(): void
    {
        $this->assertEquals('heroicon-o-key', NavigationIcon::Key->value);
        $this->assertEquals('heroicon-o-globe-alt', NavigationIcon::GlobeAlt->value);
        $this->assertEquals('heroicon-o-magnifying-glass', NavigationIcon::MagnifyingGlass->value);
        $this->assertEquals('heroicon-o-server-stack', NavigationIcon::ServerStack->value);
        $this->assertEquals('heroicon-o-arrow-path-rounded-square', NavigationIcon::ArrowPathRoundedSquare->value);
        $this->assertEquals('heroicon-o-bell-alert', NavigationIcon::BellAlert->value);
        $this->assertEquals('heroicon-o-cog', NavigationIcon::Cog->value);
        $this->assertEquals('heroicon-o-rectangle-stack', NavigationIcon::RectangleStack->value);
        $this->assertEquals('heroicon-o-cloud-arrow-up', NavigationIcon::CloudArrowUp->value);
        $this->assertEquals('heroicon-o-inbox-stack', NavigationIcon::InboxStack->value);
        $this->assertEquals('heroicon-o-users', NavigationIcon::Users->value);
    }

    #[Test]
    public function all_icons_follow_heroicon_naming_convention(): void
    {
        foreach (NavigationIcon::cases() as $icon) {
            $this->assertStringStartsWith('heroicon-o-', $icon->value);
        }
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('heroicon-o-key', (string) NavigationIcon::Key->value);
        $this->assertEquals('heroicon-o-globe-alt', (string) NavigationIcon::GlobeAlt->value);
        $this->assertEquals('heroicon-o-server-stack', (string) NavigationIcon::ServerStack->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(NavigationIcon::Key, NavigationIcon::from('heroicon-o-key'));
        $this->assertEquals(NavigationIcon::GlobeAlt, NavigationIcon::from('heroicon-o-globe-alt'));
        $this->assertEquals(NavigationIcon::MagnifyingGlass, NavigationIcon::from('heroicon-o-magnifying-glass'));
        $this->assertEquals(NavigationIcon::ServerStack, NavigationIcon::from('heroicon-o-server-stack'));
        $this->assertEquals(NavigationIcon::BellAlert, NavigationIcon::from('heroicon-o-bell-alert'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(NavigationIcon::tryFrom('invalid-icon'));
        $this->assertNull(NavigationIcon::tryFrom('heroicon-o-nonexistent'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $key1 = NavigationIcon::Key;
        $key2 = NavigationIcon::Key;
        $globe = NavigationIcon::GlobeAlt;

        $this->assertTrue($key1 === $key2);
        $this->assertFalse($key1 === $globe);
    }
}
