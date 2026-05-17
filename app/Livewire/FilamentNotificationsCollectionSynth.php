<?php

namespace App\Livewire;

use Filament\Notifications\Collection as FilamentNotificationsCollection;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportWireables\WireableSynth;

class FilamentNotificationsCollectionSynth extends WireableSynth
{
    public static $key = 'wrbl';

    private const NOTIFICATION_KEYS = [
        'actions',
        'body',
        'color',
        'duration',
        'icon',
        'iconColor',
        'id',
        'status',
        'title',
        'view',
        'viewData',
    ];

    public static function match($target): bool
    {
        return $target instanceof FilamentNotificationsCollection;
    }

    public function hydrate($value, $meta, $hydrateChild): mixed
    {
        $class = $meta['class'] ?? null;

        if (! is_string($class) || ! is_a($class, FilamentNotificationsCollection::class, true)) {
            return parent::hydrate($value, $meta, $hydrateChild);
        }

        if (! is_array($value)) {
            $value = [];
        }

        foreach ($value as $key => $child) {
            $value[$key] = $hydrateChild($key, $child);
        }

        return app($class, ['items' => $this->normalizeNotifications($value)])
            ->transform(fn (array|Notification $notification): Notification => $notification instanceof Notification
                ? $notification
                : Notification::fromArray($notification));
    }

    private function normalizeNotifications(array $value): array
    {
        if ($this->looksLikeNotificationPayload($value)) {
            $notification = $this->normalizeNotificationPayload($value);

            return [$notification['id'] ?? Str::random() => $notification];
        }

        $notifications = [];

        foreach ($value as $key => $notification) {
            if ($notification instanceof Notification) {
                $notifications[$key] = $notification;

                continue;
            }

            if (! is_array($notification) || ! $this->looksLikeNotificationPayload($notification)) {
                continue;
            }

            $notifications[$key] = $this->normalizeNotificationPayload($notification);
        }

        return $notifications;
    }

    private function normalizeNotificationPayload(array $notification): array
    {
        if (array_key_exists('id', $notification)) {
            $notification['id'] = is_scalar($notification['id']) ? (string) $notification['id'] : null;
        }

        if (blank($notification['id'] ?? null)) {
            unset($notification['id']);
        }

        if (array_key_exists('actions', $notification)) {
            $notification['actions'] = $this->normalizeNotificationActions($notification['actions']);
        }

        if (array_key_exists('viewData', $notification) && ! is_array($notification['viewData'])) {
            $notification['viewData'] = [];
        }

        return $notification;
    }

    private function normalizeNotificationActions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        $normalized = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            if (array_key_exists('actions', $action)) {
                continue;
            }

            if (blank($action['name'] ?? null)) {
                continue;
            }

            $action['isOutlined'] = (bool) ($action['isOutlined'] ?? false);
            $action['isDisabled'] = (bool) ($action['isDisabled'] ?? false);
            $action['shouldClose'] = (bool) ($action['shouldClose'] ?? false);
            $action['shouldMarkAsRead'] = (bool) ($action['shouldMarkAsRead'] ?? false);
            $action['shouldMarkAsUnread'] = (bool) ($action['shouldMarkAsUnread'] ?? false);
            $action['shouldOpenUrlInNewTab'] = (bool) ($action['shouldOpenUrlInNewTab'] ?? false);
            $action['shouldPostToUrl'] = (bool) ($action['shouldPostToUrl'] ?? false);

            $normalized[] = $action;
        }

        return $normalized;
    }

    private function looksLikeNotificationPayload(array $value): bool
    {
        return filled(array_intersect(array_keys($value), self::NOTIFICATION_KEYS));
    }
}
