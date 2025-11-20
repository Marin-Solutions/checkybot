<?php

namespace Tests\Unit\Services;

use App\Services\IntervalParser;
use PHPUnit\Framework\TestCase;

class IntervalParserTest extends TestCase
{
    public function test_parses_minutes_correctly(): void
    {
        $this->assertEquals(5, IntervalParser::toMinutes('5m'));
        $this->assertEquals(1, IntervalParser::toMinutes('1m'));
        $this->assertEquals(30, IntervalParser::toMinutes('30m'));
    }

    public function test_parses_hours_to_minutes_correctly(): void
    {
        $this->assertEquals(60, IntervalParser::toMinutes('1h'));
        $this->assertEquals(120, IntervalParser::toMinutes('2h'));
        $this->assertEquals(180, IntervalParser::toMinutes('3h'));
    }

    public function test_parses_days_to_minutes_correctly(): void
    {
        $this->assertEquals(1440, IntervalParser::toMinutes('1d'));
        $this->assertEquals(2880, IntervalParser::toMinutes('2d'));
        $this->assertEquals(4320, IntervalParser::toMinutes('3d'));
    }

    public function test_throws_exception_for_invalid_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval format: invalid');

        IntervalParser::toMinutes('invalid');
    }

    public function test_throws_exception_for_missing_unit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntervalParser::toMinutes('5');
    }

    public function test_throws_exception_for_missing_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntervalParser::toMinutes('m');
    }

    public function test_throws_exception_for_unknown_unit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntervalParser::toMinutes('5x');
    }

    public function test_validates_correct_interval_formats(): void
    {
        $this->assertTrue(IntervalParser::isValid('5m'));
        $this->assertTrue(IntervalParser::isValid('2h'));
        $this->assertTrue(IntervalParser::isValid('1d'));
        $this->assertTrue(IntervalParser::isValid('30m'));
    }

    public function test_rejects_invalid_interval_formats(): void
    {
        $this->assertFalse(IntervalParser::isValid('5'));
        $this->assertFalse(IntervalParser::isValid('m'));
        $this->assertFalse(IntervalParser::isValid('invalid'));
        $this->assertFalse(IntervalParser::isValid('5x'));
        $this->assertFalse(IntervalParser::isValid(''));
    }

    public function test_converts_minutes_back_to_interval_string(): void
    {
        $this->assertEquals('5m', IntervalParser::fromMinutes(5));
        $this->assertEquals('1h', IntervalParser::fromMinutes(60));
        $this->assertEquals('2h', IntervalParser::fromMinutes(120));
        $this->assertEquals('1d', IntervalParser::fromMinutes(1440));
        $this->assertEquals('2d', IntervalParser::fromMinutes(2880));
    }

    public function test_prefers_hours_over_minutes_when_converting(): void
    {
        $this->assertEquals('1h', IntervalParser::fromMinutes(60));
        $this->assertEquals('2h', IntervalParser::fromMinutes(120));
    }

    public function test_prefers_days_over_hours_when_converting(): void
    {
        $this->assertEquals('1d', IntervalParser::fromMinutes(1440));
        $this->assertEquals('2d', IntervalParser::fromMinutes(2880));
    }

    public function test_uses_minutes_when_not_evenly_divisible_by_hours(): void
    {
        $this->assertEquals('65m', IntervalParser::fromMinutes(65));
        $this->assertEquals('90m', IntervalParser::fromMinutes(90));
        $this->assertEquals('25h', IntervalParser::fromMinutes(1500));
    }
}
