<?php

namespace App\Enums;

enum NotificationChannelTypesEnum: string
{
    case MAIL = 'MAIL';
    case WEBHOOK = 'WEBHOOK';

    public function label(): string
    {
        return match ($this) {
            self::MAIL => 'Email',
            self::WEBHOOK => 'Webhook',
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
