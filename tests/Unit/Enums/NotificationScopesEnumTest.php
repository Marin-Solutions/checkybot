<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationScopesEnum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationScopesEnumTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = NotificationScopesEnum::cases();

        $this->assertCount(2, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = NotificationScopesEnum::cases();

        $this->assertContains(NotificationScopesEnum::GLOBAL, $cases);
        $this->assertContains(NotificationScopesEnum::WEBSITE, $cases);
    }

    #[Test]
    public function it_has_correct_values_for_all_cases(): void
    {
        $this->assertEquals('GLOBAL', NotificationScopesEnum::GLOBAL->value);
        $this->assertEquals('WEBSITE', NotificationScopesEnum::WEBSITE->value);
    }

    #[Test]
    public function it_returns_correct_labels(): void
    {
        $this->assertEquals('Global', NotificationScopesEnum::GLOBAL->label());
        $this->assertEquals('Website', NotificationScopesEnum::WEBSITE->label());
    }

    #[Test]
    public function label_method_returns_capitalized_lowercase_value(): void
    {
        foreach (NotificationScopesEnum::cases() as $case) {
            $expectedLabel = ucfirst(strtolower($case->value));
            $this->assertEquals($expectedLabel, $case->label());
        }
    }

    #[Test]
    public function keys_method_returns_array_of_case_names(): void
    {
        $keys = NotificationScopesEnum::keys();

        $this->assertIsArray($keys);
        $this->assertCount(2, $keys);
        $this->assertContains('GLOBAL', $keys);
        $this->assertContains('WEBSITE', $keys);
    }

    #[Test]
    public function to_array_returns_associative_array_of_values_and_labels(): void
    {
        $array = NotificationScopesEnum::toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertEquals([
            'GLOBAL' => 'Global',
            'WEBSITE' => 'Website',
        ], $array);
    }

    #[Test]
    public function to_array_keys_match_enum_values(): void
    {
        $array = NotificationScopesEnum::toArray();
        $keys = array_keys($array);

        foreach (NotificationScopesEnum::cases() as $case) {
            $this->assertContains($case->value, $keys);
        }
    }

    #[Test]
    public function to_array_values_match_labels(): void
    {
        $array = NotificationScopesEnum::toArray();

        foreach (NotificationScopesEnum::cases() as $case) {
            $this->assertEquals($case->label(), $array[$case->value]);
        }
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('GLOBAL', (string) NotificationScopesEnum::GLOBAL->value);
        $this->assertEquals('WEBSITE', (string) NotificationScopesEnum::WEBSITE->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(NotificationScopesEnum::GLOBAL, NotificationScopesEnum::from('GLOBAL'));
        $this->assertEquals(NotificationScopesEnum::WEBSITE, NotificationScopesEnum::from('WEBSITE'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(NotificationScopesEnum::tryFrom('LOCAL'));
        $this->assertNull(NotificationScopesEnum::tryFrom('USER'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $global1 = NotificationScopesEnum::GLOBAL;
        $global2 = NotificationScopesEnum::GLOBAL;
        $website = NotificationScopesEnum::WEBSITE;

        $this->assertTrue($global1 === $global2);
        $this->assertFalse($global1 === $website);
    }
}
