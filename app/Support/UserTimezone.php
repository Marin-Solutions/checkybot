<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Resolves the timezone string used to render dates for the current viewer.
 *
 * Falls back to the configured application timezone when the viewer is a
 * guest, has not picked a timezone yet, or is using a value PHP cannot parse.
 */
class UserTimezone
{
    /**
     * Get the timezone identifier for the currently authenticated user.
     *
     * Returns null so callers can pass it straight into Filament APIs that
     * accept null (and therefore fall back to the table/column default).
     */
    public static function current(): ?string
    {
        $user = Auth::user();

        $timezone = $user?->timezone;

        if (blank($timezone)) {
            return null;
        }

        return static::isValid($timezone) ? $timezone : null;
    }

    /**
     * Get the viewer timezone or fall back to the configured app timezone.
     */
    public static function currentOrAppDefault(): string
    {
        return static::current() ?? config('app.timezone', 'UTC');
    }

    /**
     * Validate that the provided timezone identifier is a known PHP zone.
     */
    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The list of timezone identifiers offered to users in the profile page.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(\DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $tz): array => [$tz => $tz])
            ->all();
    }
}
