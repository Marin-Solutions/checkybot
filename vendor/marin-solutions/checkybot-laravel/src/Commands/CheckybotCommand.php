<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Components\HealthComponent;
use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Support\Interval;

class CheckybotCommand extends Command
{
    public $signature = 'checkybot:sync
                        {--dry-run : Show what would be synced without actually syncing}';

    public $description = 'Sync monitoring checks with CheckyBot platform';

    public function handle(ConfigValidator $validator, CheckRegistry $registry): int
    {
        $this->info('Checkybot Sync Starting...');

        $config = config('checkybot-laravel');

        // Use registry if checks are defined there, otherwise fall back to config
        $useRegistry = $registry->count() > 0;

        if ($useRegistry) {
            $validation = $validator->validateWithRegistry($config, $registry);
        } else {
            $validation = $validator->validate($config);
        }

        if (! $validation['valid']) {
            $this->error('Configuration validation failed:');
            foreach ($validation['errors'] as $error) {
                $this->error('  - '.$error);
            }

            return self::FAILURE;
        }

        // Get payload from registry or config
        $checkPayload = $useRegistry
            ? $registry->toCheckArray()
            : $validator->transformPayload($config);

        $totalChecks = count($checkPayload['uptime_checks'])
            + count($checkPayload['ssl_checks'])
            + count($checkPayload['api_checks']);

        $observedAt = now();
        $componentContextKey = $this->resolveComponentContextKey($config);
        $dueComponents = $useRegistry
            ? $this->getDueComponents($registry, $observedAt, $componentContextKey)
            : [];

        $this->comment("Found {$totalChecks} checks to sync and ".count($dueComponents).' due components to report');

        if ($this->option('dry-run')) {
            $this->displayDryRun($checkPayload, $dueComponents);

            return self::SUCCESS;
        }

        try {
            /** @var CheckybotClient $client */
            $client = app(CheckybotClient::class);
            $client->registerApplication($this->buildRegistrationPayload($config));

            $response = $client->syncChecks($checkPayload);
            $this->displaySyncResults($response['summary'] ?? []);

            if ($dueComponents !== []) {
                $componentPayload = [
                    'components' => array_map(
                        fn (HealthComponent $component): array => $component->toHeartbeatPayload($observedAt),
                        $dueComponents
                    ),
                ];

                $client->syncComponents($componentPayload);
                $this->markComponentsAsReported($dueComponents, $observedAt, $componentContextKey);
            }

            $this->info('Sync completed successfully');

            return self::SUCCESS;
        } catch (CheckybotSyncException $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $payload
     * @param  array<int, HealthComponent>  $dueComponents
     */
    protected function displayDryRun(array $payload, array $dueComponents): void
    {
        $this->line('');
        $this->comment('DRY RUN - No changes will be made');
        $this->line('');

        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $type) {
            if (! empty($payload[$type])) {
                $this->info(ucwords(str_replace('_', ' ', $type)).':');
                foreach ($payload[$type] as $check) {
                    $this->line("  - {$check['name']} ({$check['url']}) every {$check['interval']}");
                }
                $this->line('');
            }
        }

        if ($dueComponents !== []) {
            $this->info('Components:');
            foreach ($dueComponents as $component) {
                $this->line("  - {$component->getName()} every {$component->getInterval()}");
            }
            $this->line('');
        }
    }

    /**
     * @param  array<string, array<string, int>>  $summary
     */
    protected function displaySyncResults(array $summary): void
    {
        $this->line('');
        $this->info('Sync Summary:');

        foreach ($summary as $type => $counts) {
            $label = ucwords(str_replace('_', ' ', $type));
            $this->line("  {$label}:");
            $this->line("    Created: {$counts['created']}");
            $this->line("    Updated: {$counts['updated']}");
            $this->line("    Deleted: {$counts['deleted']}");
        }

        $this->line('');
    }

    /**
     * @return array<int, HealthComponent>
     */
    protected function getDueComponents(CheckRegistry $registry, Carbon $observedAt, string $componentContextKey): array
    {
        return array_values(array_filter(
            $registry->getComponents(),
            fn (HealthComponent $component): bool => $this->isComponentDue($component, $observedAt, $componentContextKey)
        ));
    }

    protected function isComponentDue(HealthComponent $component, Carbon $observedAt, string $componentContextKey): bool
    {
        $lastReportedAt = Cache::get($this->componentCacheKey($componentContextKey, $component));

        return Interval::isDue(
            $component->getInterval(),
            $lastReportedAt ? Carbon::parse($lastReportedAt) : null,
            $observedAt
        );
    }

    /**
     * @param  array<int, HealthComponent>  $components
     */
    protected function markComponentsAsReported(array $components, Carbon $observedAt, string $componentContextKey): void
    {
        foreach ($components as $component) {
            Cache::forever($this->componentCacheKey($componentContextKey, $component), $observedAt->toISOString());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildRegistrationPayload(array $config): array
    {
        $payload = [
            'name' => $config['application_name'],
            'environment' => $config['environment'],
            'identity_endpoint' => $config['identity_endpoint'],
            'technology' => 'Laravel',
        ];

        if (filled($config['app_id'] ?? null)) {
            $payload['app_id'] = (string) $config['app_id'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveComponentContextKey(array $config): string
    {
        if (filled($config['app_id'] ?? null)) {
            return 'app:'.$config['app_id'];
        }

        if (filled($config['project_id'] ?? null)) {
            return 'project:'.$config['project_id'];
        }

        return 'identity:'.sha1(sprintf(
            '%s|%s',
            $config['environment'] ?? '',
            $config['identity_endpoint'] ?? '',
        ));
    }

    protected function componentCacheKey(string $componentContextKey, HealthComponent $component): string
    {
        return sprintf(
            'checkybot-laravel.components.%s.%s.last_reported_at',
            $componentContextKey,
            $component->getName(),
        );
    }
}
