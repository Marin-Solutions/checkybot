<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Base class for all check types.
 *
 * Provides common functionality shared across uptime, SSL, and API checks.
 *
 * @internal
 */
abstract class BaseCheck
{
    /**
     * The unique name/identifier for this check.
     */
    protected string $name;

    /**
     * The URL to monitor.
     */
    protected string $url = '';

    /**
     * Check interval (e.g., '5m', '1h', '1d').
     */
    protected string $interval = '5m';

    /**
     * Create a new check instance.
     *
     * @param  string  $name  Unique identifier for this check
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Set the URL to monitor.
     *
     * @param  string  $url  Full URL to monitor
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::uptime('homepage')
     *     ->url('https://example.com');
     * ```
     */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Set how often the check should run.
     *
     * Accepts interval strings like '1m', '5m', '1h', '1d'.
     *
     * @param  string  $interval  Interval string (e.g., '5m' for 5 minutes)
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::uptime('homepage')
     *     ->url('https://example.com')
     *     ->every('5m');
     * ```
     */
    public function every(string $interval): static
    {
        $this->interval = $interval;

        return $this;
    }

    /**
     * Alias for every() - set the check interval.
     *
     * @param  string  $interval  Interval string (e.g., '5m' for 5 minutes)
     * @return $this
     *
     * @see every()
     */
    public function interval(string $interval): static
    {
        return $this->every($interval);
    }

    /**
     * Run this check every second.
     *
     * @return $this
     */
    public function everySecond(): static
    {
        return $this->every('1s');
    }

    /**
     * Run this check every two seconds.
     *
     * @return $this
     */
    public function everyTwoSeconds(): static
    {
        return $this->every('2s');
    }

    /**
     * Run this check every five seconds.
     *
     * @return $this
     */
    public function everyFiveSeconds(): static
    {
        return $this->every('5s');
    }

    /**
     * Run this check every ten seconds.
     *
     * @return $this
     */
    public function everyTenSeconds(): static
    {
        return $this->every('10s');
    }

    /**
     * Run this check every fifteen seconds.
     *
     * @return $this
     */
    public function everyFifteenSeconds(): static
    {
        return $this->every('15s');
    }

    /**
     * Run this check every twenty seconds.
     *
     * @return $this
     */
    public function everyTwentySeconds(): static
    {
        return $this->every('20s');
    }

    /**
     * Run this check every thirty seconds.
     *
     * @return $this
     */
    public function everyThirtySeconds(): static
    {
        return $this->every('30s');
    }

    /**
     * Run this check every minute.
     *
     * @return $this
     */
    public function everyMinute(): static
    {
        return $this->every('1m');
    }

    /**
     * Run this check every two minutes.
     *
     * @return $this
     */
    public function everyTwoMinutes(): static
    {
        return $this->every('2m');
    }

    /**
     * Run this check every three minutes.
     *
     * @return $this
     */
    public function everyThreeMinutes(): static
    {
        return $this->every('3m');
    }

    /**
     * Run this check every four minutes.
     *
     * @return $this
     */
    public function everyFourMinutes(): static
    {
        return $this->every('4m');
    }

    /**
     * Run this check every five minutes.
     *
     * @return $this
     */
    public function everyFiveMinutes(): static
    {
        return $this->every('5m');
    }

    /**
     * Run this check every ten minutes.
     *
     * @return $this
     */
    public function everyTenMinutes(): static
    {
        return $this->every('10m');
    }

    /**
     * Run this check every fifteen minutes.
     *
     * @return $this
     */
    public function everyFifteenMinutes(): static
    {
        return $this->every('15m');
    }

    /**
     * Run this check every thirty minutes.
     *
     * @return $this
     */
    public function everyThirtyMinutes(): static
    {
        return $this->every('30m');
    }

    /**
     * Run this check every hour.
     *
     * @return $this
     */
    public function hourly(): static
    {
        return $this->every('1h');
    }

    /**
     * Run this check every two hours.
     *
     * @return $this
     */
    public function everyTwoHours(): static
    {
        return $this->every('2h');
    }

    /**
     * Run this check every three hours.
     *
     * @return $this
     */
    public function everyThreeHours(): static
    {
        return $this->every('3h');
    }

    /**
     * Run this check every four hours.
     *
     * @return $this
     */
    public function everyFourHours(): static
    {
        return $this->every('4h');
    }

    /**
     * Run this check every six hours.
     *
     * @return $this
     */
    public function everySixHours(): static
    {
        return $this->every('6h');
    }

    /**
     * Run this check every twelve hours (twice daily).
     *
     * @return $this
     */
    public function twiceDaily(): static
    {
        return $this->every('12h');
    }

    /**
     * Run this check every day.
     *
     * @return $this
     */
    public function daily(): static
    {
        return $this->every('1d');
    }

    /**
     * Run this check every week.
     *
     * @return $this
     */
    public function weekly(): static
    {
        return $this->every('7d');
    }

    /**
     * Get the check name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the check URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the check interval.
     */
    public function getInterval(): string
    {
        return $this->interval;
    }

    /**
     * Convert the check to array format for the API.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
