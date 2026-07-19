<?php

namespace App\Console\Commands;

use App\Models\NotificationChannels;
use App\Services\HealthSummaryNotificationService;
use Illuminate\Console\Command;
use Throwable;

class SendHealthSummaries extends Command
{
    protected $signature = 'notifications:send-health-summaries';

    protected $description = 'Send due account health summaries to configured webhook channels';

    public function handle(HealthSummaryNotificationService $summaries): int
    {
        NotificationChannels::query()
            ->healthSummaryEnabled()
            ->eachById(function (NotificationChannels $channel) use ($summaries): void {
                if (! $channel->claimHealthSummaryAttempt()) {
                    return;
                }

                try {
                    $summaries->send($channel);
                } catch (Throwable $exception) {
                    $channel->recordDeliveryAttempt(
                        kind: 'health_summary',
                        succeeded: false,
                        responseCode: null,
                        summary: 'Unexpected health summary error: '.$exception->getMessage(),
                    );
                    report($exception);
                }
            });

        return self::SUCCESS;
    }
}
