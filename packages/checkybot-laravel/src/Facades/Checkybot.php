<?php

namespace MarinSolutions\CheckybotLaravel\Facades;

use Illuminate\Support\Facades\Facade;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;
use MarinSolutions\CheckybotLaravel\Components\HealthComponent;

/**
 * Facade for defining monitoring checks.
 *
 * Provides a fluent, expressive API for defining uptime, SSL, and API checks
 * that will be synced to your Checkybot instance.
 *
 * @method static UptimeCheck uptime(string $name) Create a new uptime check
 * @method static SslCheck ssl(string $name) Create a new SSL certificate check
 * @method static ApiCheck api(string $name) Create a new API endpoint check
 * @method static HealthComponent component(string $name) Create a new health component
 * @method static array<int, UptimeCheck> getUptimeChecks() Get all registered uptime checks
 * @method static array<int, SslCheck> getSslChecks() Get all registered SSL checks
 * @method static array<int, ApiCheck> getApiChecks() Get all registered API checks
 * @method static array<int, HealthComponent> getComponents() Get all registered health components
 * @method static int count() Get the total number of registered checks
 * @method static CheckRegistry flush() Clear all registered checks
 * @method static array toArray() Convert all checks to array format
 *
 * @see \MarinSolutions\CheckybotLaravel\CheckRegistry
 *
 * @example Uptime check
 * ```php
 * use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
 *
 * Checkybot::uptime('homepage')
 *     ->url('https://example.com')
 *     ->every('5m');
 * ```
 * @example SSL check
 * ```php
 * Checkybot::ssl('main-certificate')
 *     ->url('https://example.com')
 *     ->daily();
 * ```
 * @example API check with assertions
 * ```php
 * Checkybot::api('health')
 *     ->url('https://example.com/api/health')
 *     ->every('5m')
 *     ->expect('status')->toEqual('healthy')
 *     ->expect('database.connected')->toBeTrue();
 * ```
 */
class Checkybot extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return CheckRegistry::class;
    }
}
