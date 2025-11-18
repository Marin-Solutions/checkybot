<?php

namespace Tests\Unit\Enums;

use App\Enums\SeoIssueSeverity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIssueSeverityTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = SeoIssueSeverity::cases();

        $this->assertCount(3, $cases);
    }

    #[Test]
    public function it_contains_all_expected_case_instances(): void
    {
        $cases = SeoIssueSeverity::cases();

        $this->assertContains(SeoIssueSeverity::Error, $cases);
        $this->assertContains(SeoIssueSeverity::Warning, $cases);
        $this->assertContains(SeoIssueSeverity::Notice, $cases);
    }

    #[Test]
    public function it_has_correct_values_for_all_cases(): void
    {
        $this->assertEquals('error', SeoIssueSeverity::Error->value);
        $this->assertEquals('warning', SeoIssueSeverity::Warning->value);
        $this->assertEquals('notice', SeoIssueSeverity::Notice->value);
    }

    #[Test]
    public function it_returns_correct_labels(): void
    {
        $this->assertEquals('Error', SeoIssueSeverity::Error->getLabel());
        $this->assertEquals('Warning', SeoIssueSeverity::Warning->getLabel());
        $this->assertEquals('Notice', SeoIssueSeverity::Notice->getLabel());
    }

    #[Test]
    public function it_returns_correct_colors(): void
    {
        $this->assertEquals('danger', SeoIssueSeverity::Error->getColor());
        $this->assertEquals('warning', SeoIssueSeverity::Warning->getColor());
        $this->assertEquals('info', SeoIssueSeverity::Notice->getColor());
    }

    #[Test]
    public function it_returns_correct_priorities(): void
    {
        $this->assertEquals(1, SeoIssueSeverity::Error->getPriority());
        $this->assertEquals(2, SeoIssueSeverity::Warning->getPriority());
        $this->assertEquals(3, SeoIssueSeverity::Notice->getPriority());
    }

    #[Test]
    public function error_has_highest_priority(): void
    {
        $error = SeoIssueSeverity::Error->getPriority();
        $warning = SeoIssueSeverity::Warning->getPriority();
        $notice = SeoIssueSeverity::Notice->getPriority();

        $this->assertTrue($error < $warning);
        $this->assertTrue($warning < $notice);
    }

    #[Test]
    public function it_can_be_serialized_to_string(): void
    {
        $this->assertEquals('error', (string) SeoIssueSeverity::Error->value);
        $this->assertEquals('warning', (string) SeoIssueSeverity::Warning->value);
        $this->assertEquals('notice', (string) SeoIssueSeverity::Notice->value);
    }

    #[Test]
    public function it_can_be_instantiated_from_value(): void
    {
        $this->assertEquals(SeoIssueSeverity::Error, SeoIssueSeverity::from('error'));
        $this->assertEquals(SeoIssueSeverity::Warning, SeoIssueSeverity::from('warning'));
        $this->assertEquals(SeoIssueSeverity::Notice, SeoIssueSeverity::from('notice'));
    }

    #[Test]
    public function it_returns_null_for_invalid_value_with_try_from(): void
    {
        $this->assertNull(SeoIssueSeverity::tryFrom('critical'));
        $this->assertNull(SeoIssueSeverity::tryFrom('info'));
    }

    #[Test]
    public function it_can_be_compared_with_equality(): void
    {
        $error1 = SeoIssueSeverity::Error;
        $error2 = SeoIssueSeverity::Error;
        $warning = SeoIssueSeverity::Warning;

        $this->assertTrue($error1 === $error2);
        $this->assertFalse($error1 === $warning);
    }

    #[Test]
    public function all_severity_levels_have_unique_priorities(): void
    {
        $priorities = array_map(
            fn (SeoIssueSeverity $severity) => $severity->getPriority(),
            SeoIssueSeverity::cases()
        );

        $this->assertCount(count(SeoIssueSeverity::cases()), array_unique($priorities));
    }
}
