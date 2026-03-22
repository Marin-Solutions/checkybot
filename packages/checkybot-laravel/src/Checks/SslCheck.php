<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Fluent builder for SSL certificate monitoring checks.
 *
 * SSL checks monitor certificate expiration dates to ensure
 * your certificates are renewed before they expire.
 *
 * @example
 * ```php
 * use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
 *
 * // Simple SSL check
 * Checkybot::ssl('main-certificate')
 *     ->url('https://example.com')
 *     ->every('1d');
 *
 * // Using helper method
 * Checkybot::ssl('api-certificate')
 *     ->url('https://api.example.com')
 *     ->daily();
 * ```
 */
class SslCheck extends BaseCheck
{
    /**
     * Convert the check to array format for the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'interval' => $this->interval,
        ];
    }
}
