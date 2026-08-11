<?php

namespace App\Support;

use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Livewire\Notifications as BaseNotifications;
use Livewire\Attributes\Locked;
use Livewire\Mechanisms\ComponentRegistry;

class LivewireUpdatePayloadSanitizer
{
    /**
     * @var array<class-string, array<string, true>>
     */
    private array $lockedProperties = [];

    public function sanitize(array $payload): array
    {
        foreach ($payload as $index => $componentPayload) {
            if (! is_array($componentPayload)) {
                continue;
            }

            $snapshot = $this->decodeSnapshot($componentPayload['snapshot'] ?? null);
            $componentClass = $this->resolveComponentClass($snapshot);

            if ($componentClass !== null && isset($componentPayload['updates']) && is_array($componentPayload['updates'])) {
                $componentPayload['updates'] = $this->sanitizeUpdates($componentClass, $componentPayload['updates'], $snapshot);
            }

            $payload[$index] = $componentPayload;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function sanitizeUpdates(string $componentClass, array $updates, ?array $snapshot): array
    {
        $lockedProperties = $this->lockedPropertiesFor($componentClass);
        $shouldSanitizeMountedActions = is_a($componentClass, HasActions::class, true)
            && ! method_exists($componentClass, 'updatedMountedActions');
        $namedMountedActions = $shouldSanitizeMountedActions
            ? $this->namedMountedActionIndexes($snapshot)
            : [];

        foreach ($updates as $path => $value) {
            if (! is_string($path)) {
                unset($updates[$path]);

                continue;
            }

            $property = str($path)->before('.')->toString();

            if (isset($lockedProperties[$property])) {
                unset($updates[$path]);

                continue;
            }

            if ($shouldSanitizeMountedActions && $this->hasInvalidMountedActionUpdate($path, $value, $namedMountedActions)) {
                unset($updates[$path]);

                continue;
            }

            if (
                $property === 'isFilamentNotificationsComponent'
                && is_a($componentClass, BaseNotifications::class, true)
                && ! is_bool($value)
            ) {
                unset($updates[$path]);
            }
        }

        return $updates;
    }

    /**
     * @param  array<int, true>  $namedMountedActions
     */
    private function hasInvalidMountedActionUpdate(string $path, mixed $value, array $namedMountedActions): bool
    {
        if (preg_match('/^mountedActions\.(0|[1-9]\d*)\.(.+)$/', $path, $matches) === 1) {
            $statePath = $matches[2];

            return ! isset($namedMountedActions[(int) $matches[1]])
                || ($statePath !== 'data' && ! str_starts_with($statePath, 'data.'));
        }

        if (preg_match('/^mountedActions\.(0|[1-9]\d*)$/', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, 'mountedActions.')) {
            return true;
        }

        if ($path !== 'mountedActions') {
            return false;
        }

        return ! is_array($value) || $value !== [];
    }

    /**
     * @return array<int, true>
     */
    private function namedMountedActionIndexes(?array $snapshot): array
    {
        $mountedActions = $this->unwrapSnapshotArray($snapshot['data']['mountedActions'] ?? null);

        if ($mountedActions === null) {
            return [];
        }

        $namedMountedActions = [];

        foreach ($mountedActions as $index => $action) {
            $action = $this->unwrapSnapshotArray($action);
            $name = $action['name'] ?? null;

            if (is_int($index) && is_string($name) && filled($name)) {
                $namedMountedActions[$index] = true;
            }
        }

        return $namedMountedActions;
    }

    private function unwrapSnapshotArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        if (
            array_is_list($value)
            && count($value) === 2
            && is_array($value[0])
            && (($value[1]['s'] ?? null) === 'arr')
        ) {
            return $value[0];
        }

        return $value;
    }

    /**
     * @return array<string, true>
     */
    private function lockedPropertiesFor(string $componentClass): array
    {
        if (isset($this->lockedProperties[$componentClass])) {
            return $this->lockedProperties[$componentClass];
        }

        $lockedProperties = [];

        try {
            $reflection = new \ReflectionClass($componentClass);
        } catch (\ReflectionException) {
            return $this->lockedProperties[$componentClass] = [];
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Locked::class) !== []) {
                $lockedProperties[$property->getName()] = true;
            }
        }

        return $this->lockedProperties[$componentClass] = $lockedProperties;
    }

    /**
     * @return class-string|null
     */
    private function resolveComponentClass(?array $snapshot): ?string
    {
        $name = $snapshot['memo']['name'] ?? null;

        if (! is_string($name) || blank($name)) {
            return null;
        }

        try {
            $class = app(ComponentRegistry::class)->getClass($name);
        } catch (\Throwable) {
            return null;
        }

        return is_string($class) && class_exists($class) ? $class : null;
    }

    private function decodeSnapshot(mixed $snapshot): ?array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (! is_string($snapshot) || blank($snapshot)) {
            return null;
        }

        $decoded = json_decode($snapshot, associative: true);

        return is_array($decoded) ? $decoded : null;
    }
}
