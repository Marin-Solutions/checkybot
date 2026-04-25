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
     * Memoized lookup table of known PHP timezone identifiers keyed by the
     * identifier itself, so validation runs in O(1) without rebuilding the
     * ~600-entry list on every call.
     *
     * @var array<string, true>|null
     */
    protected static ?array $identifierLookup = null;

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
        return isset(static::identifierLookup()[$timezone]);
    }

    /**
     * The full list of selectable timezone identifiers, served from the
     * memoized lookup so callers (the profile select, the validation rule,
     * and `isValid`) all read from the same source of truth.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return array_keys(static::identifierLookup());
    }

    /**
     * The list of timezone identifiers offered to users in the profile page.
     *
     * Each label includes the current UTC offset (e.g. "Europe/Berlin
     * (UTC+02:00)") so users can pick the right zone without referencing
     * an external table.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $reference = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return collect(static::identifiers())
            ->mapWithKeys(static function (string $tz) use ($reference): array {
                $offsetSeconds = (new \DateTimeZone($tz))->getOffset($reference);
                $sign = $offsetSeconds >= 0 ? '+' : '-';
                $hours = intdiv(abs($offsetSeconds), 3600);
                $minutes = intdiv(abs($offsetSeconds) % 3600, 60);

                return [
                    $tz => sprintf('%s (UTC%s%02d:%02d)', $tz, $sign, $hours, $minutes),
                ];
            })
            ->all();
    }

    /**
     * Reset the memoized identifier lookup. Intended for use in tests that
     * exercise different PHP timezone databases.
     */
    public static function flushIdentifierCache(): void
    {
        static::$identifierLookup = null;
    }

    /**
     * @return array<string, true>
     */
    protected static function identifierLookup(): array
    {
        return static::$identifierLookup ??= array_fill_keys(\DateTimeZone::listIdentifiers(), true);
    }
}
