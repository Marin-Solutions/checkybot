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
                $componentPayload['updates'] = $this->sanitizeUpdates($componentClass, $componentPayload['updates']);
            }

            $payload[$index] = $componentPayload;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function sanitizeUpdates(string $componentClass, array $updates): array
    {
        $lockedProperties = $this->lockedPropertiesFor($componentClass);
        $shouldSanitizeMountedActions = is_a($componentClass, HasActions::class, true)
            && ! method_exists($componentClass, 'updatedMountedActions');

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

            if ($shouldSanitizeMountedActions && $this->hasNamelessMountedAction($path, $value)) {
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

    private function hasNamelessMountedAction(string $path, mixed $value): bool
    {
        if (preg_match('/^mountedActions\.\d+\.name$/', $path) === 1) {
            return blank($value);
        }

        if (preg_match('/^mountedActions\.\d+$/', $path) === 1) {
            return ! is_array($value) || blank($value['name'] ?? null);
        }

        if ($path !== 'mountedActions' || ! is_array($value)) {
            return false;
        }

        foreach ($value as $action) {
            if (! is_array($action) || blank($action['name'] ?? null)) {
                return true;
            }
        }

        return false;
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
