<?php

namespace Tests\Unit\Enums;

use App\Enums\WebhookHttpMethod;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebhookHttpMethodTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = WebhookHttpMethod::cases();

        $this->assertCount(2, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = WebhookHttpMethod::cases();

        $this->assertContains(WebhookHttpMethod::GET, $cases);
        $this->assertContains(WebhookHttpMethod::POST, $cases);
    }

    #[Test]
    public function it_has_correct_values_for_all_cases(): void
    {
        $this->assertEquals('GET', WebhookHttpMethod::GET->value);
        $this->assertEquals('POST', WebhookHttpMethod::POST->value);
    }

    #[Test]
    public function all_values_are_uppercase(): void
    {
        foreach (WebhookHttpMethod::cases() as $method) {
            $this->assertEquals(strtoupper($method->value), $method->value);
        }
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('GET', (string) WebhookHttpMethod::GET->value);
        $this->assertEquals('POST', (string) WebhookHttpMethod::POST->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(WebhookHttpMethod::GET, WebhookHttpMethod::from('GET'));
        $this->assertEquals(WebhookHttpMethod::POST, WebhookHttpMethod::from('POST'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(WebhookHttpMethod::tryFrom('PUT'));
        $this->assertNull(WebhookHttpMethod::tryFrom('DELETE'));
        $this->assertNull(WebhookHttpMethod::tryFrom('PATCH'));
    }

    #[Test]
    public function it_is_case_sensitive(): void
    {
        $this->assertNull(WebhookHttpMethod::tryFrom('get'));
        $this->assertNull(WebhookHttpMethod::tryFrom('post'));
        $this->assertNull(WebhookHttpMethod::tryFrom('Get'));
        $this->assertNull(WebhookHttpMethod::tryFrom('Post'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $get1 = WebhookHttpMethod::GET;
        $get2 = WebhookHttpMethod::GET;
        $post = WebhookHttpMethod::POST;

        $this->assertTrue($get1 === $get2);
        $this->assertFalse($get1 === $post);
    }
}
