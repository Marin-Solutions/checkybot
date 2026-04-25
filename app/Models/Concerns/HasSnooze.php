<?php

namespace App\Models\Concerns;

/**
 * Adds the silenced_until check that monitor models share.
 *
 * Both Website and MonitorApis carry a nullable `silenced_until` timestamp
 * that suppresses notifications during a maintenance window. The check is
 * always the same — null or in the past means "fire alerts" — so it lives
 * here to keep the two models in lock-step.
 *
 * @property \Illuminate\Support\Carbon|null $silenced_until
 */
trait HasSnooze
{
    public function isSilenced(): bool
    {
        return $this->silenced_until !== null && $this->silenced_until->isFuture();
    }
}
