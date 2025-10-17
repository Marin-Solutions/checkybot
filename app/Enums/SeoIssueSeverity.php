<?php

namespace App\Enums;

enum SeoIssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';

    public function getLabel(): string
    {
        return match ($this) {
            self::Error => 'Error',
            self::Warning => 'Warning',
            self::Notice => 'Notice',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Error => 'danger',
            self::Warning => 'warning',
            self::Notice => 'info',
        };
    }

    public function getPriority(): int
    {
        return match ($this) {
            self::Error => 1,
            self::Warning => 2,
            self::Notice => 3,
        };
    }
}
