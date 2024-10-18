<?php

    namespace App\Enums;

    enum WebsiteServicesEnum: string
    {
        case WEBSITE_CHECK = "WEBSITE_CHECK";
        case API_MONITOR = "API_MONITOR";
        case ALL_CHECK = "ALL_CHECK";

        public function label(): string
        {
            return match ( $this ) {
                self::WEBSITE_CHECK => "Website Check",
                self::API_MONITOR => "API Monitor",
                self::ALL_CHECK => "All Check",
            };
        }

        public static function keys(): array
        {
            return array_column(self::cases(), 'name');
        }

        public static function toArray(): array
        {
            $array = [];
            foreach ( self::cases() as $case ) {
                $array[ $case->value ] = $case->label();
            }
            return $array;
        }
    }
