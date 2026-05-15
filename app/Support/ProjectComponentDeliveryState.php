<?php

namespace App\Support;

use App\Models\ProjectComponent;
use Illuminate\Database\Eloquent\Builder;

class ProjectComponentDeliveryState
{
    public const ARCHIVED = 'archived';

    public const SNOOZED = 'snoozed';

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::SNOOZED => 'Snoozed',
            self::PENDING => 'Pending',
            self::ACTIVE => 'Active',
            self::ARCHIVED => 'Archived',
        ];
    }

    public static function value(ProjectComponent $component): string
    {
        return match (true) {
            $component->is_archived => self::ARCHIVED,
            $component->isSilenced() => self::SNOOZED,
            $component->derivedCurrentStatus() === 'pending' => self::PENDING,
            default => self::ACTIVE,
        };
    }

    public static function label(ProjectComponent $component): string
    {
        return self::options()[self::value($component)];
    }

    public static function color(string $state): string
    {
        return match ($state) {
            'Active', self::ACTIVE => 'success',
            'Pending', self::PENDING => 'warning',
            'Snoozed', self::SNOOZED => 'warning',
            default => 'gray',
        };
    }

    public static function applyFilter(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            self::ARCHIVED => $query->where('is_archived', true),
            self::SNOOZED => $query
                ->where('is_archived', false)
                ->whereNotNull('silenced_until')
                ->where('silenced_until', '>', now()),
            self::PENDING => $query
                ->where('is_archived', false)
                ->where(function (Builder $query): void {
                    $query->whereNull('silenced_until')
                        ->orWhere('silenced_until', '<=', now());
                })
                ->whereDoesntHave('activeMonitorApis')
                ->whereDoesntHave('activeWebsites'),
            self::ACTIVE => $query
                ->where('is_archived', false)
                ->where(function (Builder $query): void {
                    $query->whereNull('silenced_until')
                        ->orWhere('silenced_until', '<=', now());
                })
                ->where(function (Builder $query): void {
                    $query
                        ->whereHas('activeMonitorApis')
                        ->orWhereHas('activeWebsites');
                }),
            default => $query,
        };
    }
}
