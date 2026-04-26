<?php

namespace App\Enums;

enum UptimeTransportErrorType: string
{
    case Dns = 'dns';
    case Timeout = 'timeout';
    case Tls = 'tls';
    case Connection = 'connection';
    case Unknown = 'unknown';
}
