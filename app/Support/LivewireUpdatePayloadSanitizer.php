<?php

namespace App\Support;

use Filament\Livewire\Notifications as PanelNotifications;
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

        if ($class === PanelNotifications::class) {
            return $class;
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
