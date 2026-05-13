<?php

namespace App\Filament\Support;

use App\Enums\NotificationChannelTypesEnum;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class NotificationSettingFilters
{
    public static function deliveryOutcome(): SelectFilter
    {
        return SelectFilter::make('delivery_outcome')
            ->label('Delivery')
            ->options([
                'failed' => 'Failed delivery',
                'untested' => 'Untested',
                'succeeded' => 'Successful delivery',
            ])
            ->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'failed' => $query
                        ->whereNotNull('last_delivery_attempted_at')
                        ->where('last_delivery_succeeded', false),
                    'untested' => $query->whereNull('last_delivery_attempted_at'),
                    'succeeded' => $query
                        ->whereNotNull('last_delivery_attempted_at')
                        ->where('last_delivery_succeeded', true),
                    default => $query,
                };
            });
    }

    public static function channelType(): SelectFilter
    {
        return SelectFilter::make('channel_type')
            ->label('Channel Type')
            ->options(NotificationChannelTypesEnum::toArray());
    }

    public static function ruleState(): SelectFilter
    {
        return SelectFilter::make('rule_state')
            ->label('Rule State')
            ->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
            ])
            ->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'active' => $query->where('flag_active', true),
                    'inactive' => $query->where('flag_active', false),
                    default => $query,
                };
            });
    }

    public static function all(): array
    {
        return [
            self::deliveryOutcome(),
            self::channelType(),
            self::ruleState(),
        ];
    }
}
