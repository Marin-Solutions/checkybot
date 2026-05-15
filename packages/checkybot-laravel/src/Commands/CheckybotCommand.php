<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Components\HealthComponent;
use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

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

        $declaredComponents = $useRegistry ? $registry->getComponents() : [];

        $this->comment("Found {$totalChecks} checks to sync and ".count($declaredComponents).' components to declare');

        if ($this->option('dry-run')) {
            $this->displayDryRun($checkPayload, $declaredComponents);

            return self::SUCCESS;
        }

        try {
            /** @var CheckybotClient $client */
            $client = app(CheckybotClient::class);
            $client->registerApplication($this->buildRegistrationPayload($config));

            $response = $client->syncChecks($checkPayload);
            $this->displaySyncResults($response['summary'] ?? []);

            $componentPayload = [
                'full_manifest' => true,
                'declared_components' => array_map(
                    fn (HealthComponent $component): array => $component->toArray(),
                    $declaredComponents
                ),
            ];

            $client->syncComponents($componentPayload);

            $this->info('Sync completed successfully');

            return self::SUCCESS;
        } catch (CheckybotSyncException $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $payload
     * @param  array<int, HealthComponent>  $declaredComponents
     */
    protected function displayDryRun(array $payload, array $declaredComponents): void
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

        if ($declaredComponents !== []) {
            $this->info('Components:');
            foreach ($declaredComponents as $component) {
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

        if (filled($packageVersion = $this->resolvePackageVersion())) {
            $payload['package_version'] = $packageVersion;
        }

        if (filled($config['app_id'] ?? null)) {
            $payload['app_id'] = (string) $config['app_id'];
        }

        return $payload;
    }

    protected function resolvePackageVersion(): ?string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('marin-solutions/checkybot-laravel')) {
            return InstalledVersions::getPrettyVersion('marin-solutions/checkybot-laravel')
                ?? InstalledVersions::getVersion('marin-solutions/checkybot-laravel');
        }

        $composerPath = __DIR__.'/../../composer.json';

        if (! is_file($composerPath)) {
            return null;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);

        return is_array($composer) && filled($composer['version'] ?? null)
            ? (string) $composer['version']
            : null;
    }
}
