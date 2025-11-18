<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationChannelTypesEnum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationChannelTypesEnumTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = NotificationChannelTypesEnum::cases();

        $this->assertCount(2, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = NotificationChannelTypesEnum::cases();

        $this->assertContains(NotificationChannelTypesEnum::MAIL, $cases);
        $this->assertContains(NotificationChannelTypesEnum::WEBHOOK, $cases);
    }

    #[Test]
    public function it_has_correct_values_for_all_cases(): void
    {
        $this->assertEquals('MAIL', NotificationChannelTypesEnum::MAIL->value);
        $this->assertEquals('WEBHOOK', NotificationChannelTypesEnum::WEBHOOK->value);
    }

    #[Test]
    public function it_returns_correct_labels(): void
    {
        $this->assertEquals('Email', NotificationChannelTypesEnum::MAIL->label());
        $this->assertEquals('Webhook', NotificationChannelTypesEnum::WEBHOOK->label());
    }

    #[Test]
    public function label_method_uses_match_expression(): void
    {
        $this->assertNotEquals('Mail', NotificationChannelTypesEnum::MAIL->label());
        $this->assertEquals('Email', NotificationChannelTypesEnum::MAIL->label());
    }

    #[Test]
    public function keys_method_returns_array_of_case_names(): void
    {
        $keys = NotificationChannelTypesEnum::keys();

        $this->assertIsArray($keys);
        $this->assertCount(2, $keys);
        $this->assertContains('MAIL', $keys);
        $this->assertContains('WEBHOOK', $keys);
    }

    #[Test]
    public function to_array_returns_associative_array_of_values_and_labels(): void
    {
        $array = NotificationChannelTypesEnum::toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertEquals([
            'MAIL' => 'Email',
            'WEBHOOK' => 'Webhook',
        ], $array);
    }

    #[Test]
    public function to_array_keys_match_enum_values(): void
    {
        $array = NotificationChannelTypesEnum::toArray();
        $keys = array_keys($array);

        foreach (NotificationChannelTypesEnum::cases() as $case) {
            $this->assertContains($case->value, $keys);
        }
    }

    #[Test]
    public function to_array_values_match_labels(): void
    {
        $array = NotificationChannelTypesEnum::toArray();

        foreach (NotificationChannelTypesEnum::cases() as $case) {
            $this->assertEquals($case->label(), $array[$case->value]);
        }
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('MAIL', (string) NotificationChannelTypesEnum::MAIL->value);
        $this->assertEquals('WEBHOOK', (string) NotificationChannelTypesEnum::WEBHOOK->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(NotificationChannelTypesEnum::MAIL, NotificationChannelTypesEnum::from('MAIL'));
        $this->assertEquals(NotificationChannelTypesEnum::WEBHOOK, NotificationChannelTypesEnum::from('WEBHOOK'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(NotificationChannelTypesEnum::tryFrom('SMS'));
        $this->assertNull(NotificationChannelTypesEnum::tryFrom('SLACK'));
        $this->assertNull(NotificationChannelTypesEnum::tryFrom('PUSH'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $mail1 = NotificationChannelTypesEnum::MAIL;
        $mail2 = NotificationChannelTypesEnum::MAIL;
        $webhook = NotificationChannelTypesEnum::WEBHOOK;

        $this->assertTrue($mail1 === $mail2);
        $this->assertFalse($mail1 === $webhook);
    }
}
