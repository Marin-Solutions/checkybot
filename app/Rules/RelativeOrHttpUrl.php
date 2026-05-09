<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RelativeOrHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            $fail("The {$attribute} must be an HTTP(S) URL or a valid relative path.");
        }
    }

    public static function passes(string $value): bool
    {
        $url = trim($value);

        if ($url === '' || preg_match('/\s/', $url) === 1) {
            return false;
        }

        if (preg_match('/^https?:\/\//i', $url) === 1) {
            $parts = parse_url($url);

            return is_array($parts)
                && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                && filled($parts['host'] ?? null);
        }

        if (
            str_contains($url, '://')
            || str_starts_with($url, '//')
            || str_starts_with($url, '#')
            || preg_match('/^https?\/\//i', $url) === 1
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1
        ) {
            return false;
        }

        return parse_url($url) !== false;
    }
}
