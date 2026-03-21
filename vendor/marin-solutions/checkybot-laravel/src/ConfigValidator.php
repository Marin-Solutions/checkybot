<?php

namespace MarinSolutions\CheckybotLaravel;

class ConfigValidator
{
    /**
     * Validate config-based checks (legacy array format).
     *
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['api_key'])) {
            $errors[] = 'CHECKYBOT_API_KEY is not configured';
        }

        if (empty($config['project_id'])) {
            $errors[] = 'CHECKYBOT_PROJECT_ID is not configured';
        }

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $checks = $config['checks'] ?? [];
        $this->validateCheckNames($checks, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate registry-based checks (fluent API).
     *
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateWithRegistry(array $config, CheckRegistry $registry): array
    {
        $errors = [];

        if (empty($config['api_key'])) {
            $errors[] = 'CHECKYBOT_API_KEY is not configured';
        }

        if (empty($config['project_id'])) {
            $errors[] = 'CHECKYBOT_PROJECT_ID is not configured';
        }

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate registry check names
        $this->validateRegistryCheckNames($registry, $errors);
        $this->validateRegistryComponentNames($registry, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateRegistryCheckNames(CheckRegistry $registry, array &$errors): void
    {
        $checkTypes = [
            'uptime' => $registry->getUptimeChecks(),
            'ssl' => $registry->getSslChecks(),
            'api' => $registry->getApiChecks(),
        ];

        foreach ($checkTypes as $type => $checks) {
            $names = array_map(fn ($check) => $check->getName(), $checks);
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', array_unique($duplicates));
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateRegistryComponentNames(CheckRegistry $registry, array &$errors): void
    {
        $names = array_map(fn ($component) => $component->getName(), $registry->getComponents());
        $duplicates = array_diff_assoc($names, array_unique($names));

        if (! empty($duplicates)) {
            $errors[] = 'Duplicate component names found: '.implode(', ', array_unique($duplicates));
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $checks
     * @param  array<int, string>  $errors
     */
    protected function validateCheckNames(array $checks, array &$errors): void
    {
        foreach (['uptime', 'ssl', 'api'] as $type) {
            $names = array_column($checks[$type] ?? [], 'name');
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', array_unique($duplicates));
            }
        }
    }

    /**
     * Transform config-based checks to payload format.
     *
     * @param  array<string, mixed>  $config
     * @return array{uptime_checks: array<int, mixed>, ssl_checks: array<int, mixed>, api_checks: array<int, mixed>}
     */
    public function transformPayload(array $config): array
    {
        return [
            'uptime_checks' => $config['checks']['uptime'] ?? [],
            'ssl_checks' => $config['checks']['ssl'] ?? [],
            'api_checks' => $config['checks']['api'] ?? [],
        ];
    }
}
