<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Fluent builder for uptime monitoring checks.
 *
 * Uptime checks monitor website availability and response times.
 *
 * @example
 * ```php
 * use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
 *
 * // Simple uptime check
 * Checkybot::uptime('homepage')
 *     ->url('https://example.com')
 *     ->every('5m');
 *
 * // With max redirects
 * Checkybot::uptime('blog')
 *     ->url('https://blog.example.com')
 *     ->every('10m')
 *     ->maxRedirects(5);
 *
 * // Using helper methods
 * Checkybot::uptime('api')
 *     ->url('https://api.example.com')
 *     ->everyMinute();
 * ```
 */
class UptimeCheck extends BaseCheck
{
    /**
     * Maximum number of redirects to follow.
     */
    protected ?int $maxRedirects = null;

    /**
     * Set the maximum number of redirects to follow.
     *
     * @param  int  $max  Maximum redirects (default: 10)
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::uptime('homepage')
     *     ->url('https://example.com')
     *     ->maxRedirects(5)
     *     ->every('5m');
     * ```
     */
    public function maxRedirects(int $max): self
    {
        $this->maxRedirects = $max;

        return $this;
    }

    /**
     * Alias for maxRedirects() - follows Pest-style naming.
     *
     * @param  int  $max  Maximum redirects
     * @return $this
     *
     * @see maxRedirects()
     */
    public function followRedirects(int $max = 10): self
    {
        return $this->maxRedirects($max);
    }

    /**
     * Convert the check to array format for the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'url' => $this->url,
            'interval' => $this->interval,
        ];

        if ($this->maxRedirects !== null) {
            $data['max_redirects'] = $this->maxRedirects;
        }

        return $data;
    }
}
