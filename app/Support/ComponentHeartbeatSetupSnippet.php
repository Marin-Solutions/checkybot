<?php

namespace App\Support;

use App\Models\ProjectComponent;
use App\Services\IntervalParser;

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
            '    // Replace this cache key with your scheduler or job success signal.',
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
        $componentName = self::shellSingleQuote($component->name);
        $interval = self::shellSingleQuote(self::interval($component));
        $declaredComponents = self::shellSingleQuote(json_encode(
            self::declaredComponents($component),
            JSON_UNESCAPED_SLASHES
        ));

        return implode(PHP_EOL, [
            "COMPONENT_NAME={$componentName}",
            "COMPONENT_INTERVAL={$interval}",
            "DECLARED_COMPONENTS_JSON={$declaredComponents}",
            'OBSERVED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"',
            '',
            'curl -fsS -X POST "'.self::checkybotUrl().'/api/v1/projects/'.$project->getKey().'/components/sync" \\',
            '  -H "Authorization: Bearer '.$apiKey.'" \\',
            '  -H "Content-Type: application/json" \\',
            '  --data-binary "$(jq -n \\',
            '    --arg name "$COMPONENT_NAME" \\',
            '    --arg interval "$COMPONENT_INTERVAL" \\',
            '    --arg observed_at "$OBSERVED_AT" \\',
            '    --argjson declared_components "$DECLARED_COMPONENTS_JSON" \\',
            "    '{",
            '      declared_components: $declared_components,',
            '      components: [{',
            '        name: $name,',
            '        interval: $interval,',
            '        status: "healthy",',
            '        summary: ($name + " heartbeat completed"),',
            '        metrics: { value: 0 },',
            '        observed_at: $observed_at',
            '      }]',
            "    }'",
            '  )"',
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

    /**
     * Use the app URL as the Checkybot endpoint shown in setup snippets.
     * Empty app URLs fall back to the hosted Checkybot domain so copied
     * snippets are still runnable in new or partially-configured installs.
     */
    public static function checkybotUrl(): string
    {
        $checkybotUrl = rtrim((string) config('app.url', 'https://checkybot.com'), '/');

        return $checkybotUrl !== '' ? $checkybotUrl : 'https://checkybot.com';
    }

    private static function interval(ProjectComponent $component): string
    {
        if (filled($component->declared_interval)) {
            try {
                return IntervalParser::fromMinutes(IntervalParser::toMinutes($component->declared_interval));
            } catch (\InvalidArgumentException) {
                //
            }
        }

        return IntervalParser::fromMinutes(max(1, $component->interval_minutes ?? 5));
    }

    /**
     * @return array<int, array{name: string, interval: string}>
     */
    private static function declaredComponents(ProjectComponent $component): array
    {
        $components = $component->project
            ->components()
            ->where('source', 'package')
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        if (! $components->contains(fn (ProjectComponent $declared): bool => $declared->name === $component->name)) {
            $components->push($component);
        }

        return $components
            ->map(fn (ProjectComponent $declared): array => [
                'name' => $declared->name,
                'interval' => self::interval($declared),
            ])
            ->values()
            ->all();
    }

    private static function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private static function shellSingleQuote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
