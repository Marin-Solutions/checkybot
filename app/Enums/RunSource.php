<?php

namespace App\Enums;

enum RunSource: string
{
    case Scheduled = 'scheduled';
    case OnDemand = 'on_demand';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::OnDemand => 'Diagnostic',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::OnDemand => 'info',
        };
    }

    public function isOnDemand(): bool
    {
        return $this === self::OnDemand;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $source): array => [$source->value => $source->label()])
            ->all();
    }

    public static function coerce(mixed $source): self
    {
        return self::tryCoerce($source) ?? self::Scheduled;
    }

    public static function tryCoerce(mixed $source): ?self
    {
        if ($source instanceof self) {
            return $source;
        }

        return self::tryFrom((string) $source);
    }
}
