<?php

namespace App\Enums;

enum NotificationScopesEnum: string
{
    case GLOBAL = 'GLOBAL';
    case WEBSITE = 'WEBSITE';
    case API_MONITOR = 'API_MONITOR';
    case PROJECT_COMPONENT = 'PROJECT_COMPONENT';

    public function label(): string
    {
        return match ($this) {
            self::GLOBAL => 'Global',
            self::WEBSITE => 'Website',
            self::API_MONITOR => 'API Monitor',
            self::PROJECT_COMPONENT => 'Project Component',
        };
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
