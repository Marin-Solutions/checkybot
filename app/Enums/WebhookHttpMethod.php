<?php

    namespace App\Enums;

    enum WebhookHttpMethod: string
    {
        case GET = 'GET';
        case POST = 'POST';
    }
