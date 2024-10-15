<?php

    namespace App\Enums;

    enum WebsiteServicesEnum: string
    {
        case UPTIME_CHECK = "UPTIME_CHECK";
        case SSL_CHECK = "SSL_CHECK";
        case OUTBOUND_CHECK = "OUTBOUND_CHECK";
        case API_MONITOR = "API_MONITOR";
        case ALL_CHECK = "ALL_CHECK";

        public function label(): string
        {
            return match ( $this ) {
                self::UPTIME_CHECK => "Uptime Check",
                self::SSL_CHECK => "SSL Check",
                self::OUTBOUND_CHECK => "Outbound Check",
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
