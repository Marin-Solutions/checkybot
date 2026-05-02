<?php

namespace App\Support;

use App\Models\ProjectComponent;

class ComponentHeartbeatSetupSnippet
{
    public static function projectPackageDefinitions(): string
    {
        return implode(PHP_EOL, [
            '# routes/checkybot.php',
            '',
            "Checkybot::component('queue')",
            '    ->everyMinute()',
            "    ->metric('pending_jobs', fn (): int => \\Illuminate\\Support\\Facades\\Queue::size('default'))",
            "    ->warningWhen('>=', 100)",
            "    ->dangerWhen('>=', 500);",
            '',
            "Checkybot::component('scheduled-jobs')",
            '    ->everyFiveMinutes()',
            "    ->metric('last_successful_run_minutes_ago', fn (): int => now()->diffInMinutes(cache('checkybot:last_successful_scheduled_run', now())))",
            "    ->warningWhen('>=', 10)",
            "    ->dangerWhen('>=', 30);",
            '',
            "Checkybot::component('worker')",
            '    ->everyFiveMinutes()',
            "    ->metric('restart_required', fn (): bool => false)",
            "    ->dangerWhen('===', true);",
        ]);
    }

    public static function componentPackageDefinition(ProjectComponent $component): string
    {
        return implode(PHP_EOL, [
            '# routes/checkybot.php',
            '',
            "Checkybot::component('".self::escapeSingleQuoted($component->name)."')",
            "    ->every('".self::escapeSingleQuoted(self::interval($component))."')",
            "    ->metric('value', fn (): int => 0)",
            "    ->warningWhen('>=', 100)",
            "    ->dangerWhen('>=', 500);",
            '',
            '# routes/console.php',
            "Schedule::command('checkybot:sync')->everyMinute();",
        ]);
    }

    public static function componentCurl(ProjectComponent $component, ?string $apiKey = null): string
    {
        $project = $component->project;
        $apiKey ??= 'replace-with-your-api-key';
        $payload = [
            'declared_components' => [
                [
                    'name' => $component->name,
                    'interval' => self::interval($component),
                ],
            ],
            'components' => [
                [
                    'name' => $component->name,
                    'interval' => self::interval($component),
                    'status' => 'healthy',
                    'summary' => "{$component->name} heartbeat completed",
                    'metrics' => [
                        'value' => 0,
                    ],
                    'observed_at' => '$(date -u +%Y-%m-%dT%H:%M:%SZ)',
                ],
            ],
        ];

        return implode(PHP_EOL, [
            'curl -fsS -X POST "'.self::checkybotUrl().'/api/v1/projects/'.$project->getKey().'/components/sync" \\',
            '  -H "Authorization: Bearer '.$apiKey.'" \\',
            '  -H "Content-Type: application/json" \\',
            '  --data-binary @- <<JSON',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'JSON',
        ]);
    }

    public static function projectComponentAppendCommand(): string
    {
        return implode(PHP_EOL, [
            "cat <<'EOF' >> routes/checkybot.php",
            '',
            self::projectPackageDefinitions(),
            'EOF',
        ]);
    }

    private static function interval(ProjectComponent $component): string
    {
        if (filled($component->declared_interval)) {
            return $component->declared_interval;
        }

        return max(1, $component->interval_minutes ?: 5).'m';
    }

    private static function checkybotUrl(): string
    {
        $checkybotUrl = rtrim((string) config('app.url', 'https://checkybot.com'), '/');

        return $checkybotUrl !== '' ? $checkybotUrl : 'https://checkybot.com';
    }

    private static function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
