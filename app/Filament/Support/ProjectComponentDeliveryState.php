<?php

namespace App\Filament\Support;

use App\Models\ProjectComponent;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class ProjectComponentDeliveryState
{
    public const STALE = 'stale';

    public const AWAITING_FIRST_HEARTBEAT = 'awaiting_first_heartbeat';

    public const RECEIVING_HEARTBEATS = 'receiving_heartbeats';

    public const ARCHIVED = 'archived';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::STALE => 'Stale',
            self::AWAITING_FIRST_HEARTBEAT => 'Awaiting first heartbeat',
            self::RECEIVING_HEARTBEATS => 'Receiving heartbeats',
            self::ARCHIVED => 'Archived',
        ];
    }

    public static function state(ProjectComponent $component): string
    {
        return match (true) {
            $component->is_archived => self::ARCHIVED,
            $component->is_stale => self::STALE,
            $component->last_heartbeat_at === null => self::AWAITING_FIRST_HEARTBEAT,
            default => self::RECEIVING_HEARTBEATS,
        };
    }

    public static function label(ProjectComponent|string|null $state): string
    {
        if ($state instanceof ProjectComponent) {
            $state = self::state($state);
        }

        return self::options()[$state] ?? 'Unknown';
    }

    public static function color(ProjectComponent|string|null $state): string
    {
        if ($state instanceof ProjectComponent) {
            $state = self::state($state);
        }

        return match ($state) {
            self::RECEIVING_HEARTBEATS => 'success',
            self::AWAITING_FIRST_HEARTBEAT => 'warning',
            self::STALE => 'danger',
            default => 'gray',
        };
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('delivery_state')
            ->label('Delivery State')
            ->options(self::options())
            ->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;

                return match ($value) {
                    self::STALE => $query
                        ->where('is_archived', false)
                        ->where('is_stale', true),
                    self::AWAITING_FIRST_HEARTBEAT => $query
                        ->where('is_archived', false)
                        ->where('is_stale', false)
                        ->whereNull('last_heartbeat_at'),
                    self::RECEIVING_HEARTBEATS => $query
                        ->where('is_archived', false)
                        ->where('is_stale', false)
                        ->whereNotNull('last_heartbeat_at'),
                    self::ARCHIVED => $query->where('is_archived', true),
                    default => $query,
                };
            });
    }
}
