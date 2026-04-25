<?php

namespace App\Filament\Resources\Support;

use Filament\Forms;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

/**
 * Shared form + duration helpers for monitor snooze actions.
 *
 * Both the website and API monitor resources expose a Snooze action with the
 * same UI (1h / 4h / 24h / custom datetime). Centralising the form schema and
 * the duration parser here keeps the four call sites — row + bulk action on
 * each resource — in lock-step so adding a new preset only takes one edit.
 */
class MonitorSnoozeAction
{
    /**
     * Form schema used by every Snooze action modal.
     *
     * @return array<int, \Filament\Forms\Components\Field>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Select::make('duration')
                ->label('Snooze for')
                ->options([
                    '1h' => '1 hour',
                    '4h' => '4 hours',
                    '24h' => '24 hours',
                    'custom' => 'Custom time…',
                ])
                ->default('1h')
                ->required()
                ->live()
                ->native(false),
            Forms\Components\DateTimePicker::make('until')
                ->label('Snooze until')
                ->seconds(false)
                ->minDate(now())
                ->visible(fn (Get $get): bool => $get('duration') === 'custom')
                ->required(fn (Get $get): bool => $get('duration') === 'custom')
                ->helperText('Server timezone: '.config('app.timezone').'. Notifications resume automatically after this time.'),
        ];
    }

    /**
     * Resolve the snooze duration form data into a future Carbon instance.
     *
     * Returns null when the form data is malformed, the duration preset is
     * unknown, or the chosen target lies in the past — callers treat null
     * as a validation failure and surface an error instead of silently
     * writing a stale or unintended value.
     */
    public static function resolveUntil(array $data): ?Carbon
    {
        $until = match ($data['duration'] ?? null) {
            '1h' => now()->addHour(),
            '4h' => now()->addHours(4),
            '24h' => now()->addDay(),
            'custom' => isset($data['until']) ? Carbon::parse($data['until']) : null,
            default => null,
        };

        if ($until === null || $until->isPast()) {
            return null;
        }

        return $until;
    }
}
