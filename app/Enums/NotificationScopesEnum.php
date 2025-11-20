<?php

namespace App\Enums;

enum NotificationScopesEnum: string
{
    case GLOBAL = 'GLOBAL';
    case WEBSITE = 'WEBSITE';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public static function keys(): array
    {
        return array_column(self::cases(), 'name');
    }

    public static function toArray(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            $array[$case->value] = $case->label();
        }

        return $array;
    }
}
