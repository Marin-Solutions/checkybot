<?php

namespace App\Traits;

trait ChecksWebhookResponses
{
    /**
     * sendWebhookNotification() returns real HTTP statuses for completed
     * requests and curl errnos for caught network failures. Only HTTP 2xx
     * confirms delivery.
     *
     * @param  array{code?: int|string}  $response
     */
    protected function webhookResponseWasSuccessful(array $response): bool
    {
        $code = (int) ($response['code'] ?? 0);

        return $code >= 200 && $code < 300;
    }
}
