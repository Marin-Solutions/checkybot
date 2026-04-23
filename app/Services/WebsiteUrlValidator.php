<?php

namespace App\Services;

use App\Models\Website;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class WebsiteUrlValidator
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $inspectionCache = [];

    public static function inspect(string $url, ?int $ignoreWebsiteId = null): array
    {
        $cacheKey = self::cacheKey($url, $ignoreWebsiteId);

        if (array_key_exists($cacheKey, self::$inspectionCache)) {
            return self::$inspectionCache[$cacheKey];
        }

        $query = Website::query()->whereUrl($url);

        if ($ignoreWebsiteId !== null) {
            $query->whereKeyNot($ignoreWebsiteId);
        }

        $urlExistsInDB = $query->exists();
        $urlCheckExists = Website::checkWebsiteExists($url);
        $urlResponseCode = Website::checkResponseCode($url);

        $blockingIssues = [];
        $warnings = [];

        if ($urlExistsInDB) {
            $blockingIssues[] = [
                'title' => 'URL Website Exists in database',
                'body' => 'The website already exists in the database, try another URL.',
            ];
        }

        if (! $urlCheckExists) {
            $warnings[] = [
                'title' => 'DNS lookup failed during setup',
                'body' => 'The domain did not resolve during setup. The monitor will still be saved so you can finish configuration first.',
            ];
        }

        if (($urlResponseCode['code'] ?? null) !== 200) {
            $warnings[] = self::warningForResponse($urlResponseCode);
        }

        return self::$inspectionCache[$cacheKey] = [
            'should_halt' => filled($blockingIssues),
            'blocking_issues' => $blockingIssues,
            'warnings' => $warnings,
            'warning_state' => self::warningState($warnings),
        ];
    }

    public static function validate(string $url, callable $halt, ?int $ignoreWebsiteId = null): array
    {
        $result = self::inspect($url, $ignoreWebsiteId);

        foreach ($result['blocking_issues'] as $issue) {
            Notification::make()
                ->danger()
                ->title(__($issue['title']))
                ->body(__($issue['body']))
                ->send();
        }

        if ($result['should_halt']) {
            $halt();
        }

        foreach ($result['warnings'] as $warning) {
            Notification::make()
                ->warning()
                ->title(__($warning['title']))
                ->body(__($warning['body']))
                ->persistent()
                ->send();
        }

        return $result;
    }

    public static function warningState(array $warnings): array
    {
        if ($warnings === []) {
            return [
                'current_status' => null,
                'status_summary' => null,
            ];
        }

        return [
            'current_status' => 'warning',
            'status_summary' => Str::limit(
                collect($warnings)
                    ->pluck('body')
                    ->implode(' '),
                255,
                ''
            ),
        ];
    }

    public static function flushInspectionCache(): void
    {
        self::$inspectionCache = [];
    }

    private static function warningForResponse(array $urlResponseCode): array
    {
        $code = $urlResponseCode['code'] ?? 0;
        $body = trim((string) ($urlResponseCode['body'] ?? ''));

        if ($code === 60 || str_contains(strtolower($body), 'ssl')) {
            return [
                'title' => 'SSL issue detected during setup',
                'body' => $body !== '' ? $body : 'The target returned an SSL error during setup. The monitor will still be saved.',
            ];
        }

        if ($code >= 400) {
            return [
                'title' => 'Website returned a non-200 response',
                'body' => "The target responded with HTTP {$code} during setup. The monitor will still be saved so you can use it for staging, maintenance, or recovery work.",
            ];
        }

        return [
            'title' => 'Website could not be reached during setup',
            'body' => $body !== '' ? $body : 'The target could not be reached during setup. The monitor will still be saved.',
        ];
    }

    protected static function cacheKey(string $url, ?int $ignoreWebsiteId = null): string
    {
        return implode('|', [$url, $ignoreWebsiteId ?? 'none']);
    }
}
