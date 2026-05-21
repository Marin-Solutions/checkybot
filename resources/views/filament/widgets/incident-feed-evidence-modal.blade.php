@php
    use App\Enums\RunSource;
    use App\Models\MonitorApiResult;
    use App\Models\ProjectComponentHeartbeat;
    use App\Models\WebsiteLogHistory;
    use App\Support\ApiMonitorEvidenceFormatter;
    use App\Support\MetricsPayloadFormatter;
    use App\Support\ScheduledFailureStreak;
    use App\Support\UptimeTransportError;

    $statusColor = match ($incident->status) {
        'danger' => 'text-danger-700 bg-danger-50 ring-danger-600/20 dark:bg-danger-950/40 dark:text-danger-300 dark:ring-danger-500/30',
        'warning' => 'text-warning-700 bg-warning-50 ring-warning-600/20 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-500/30',
        'healthy', 'unknown' => 'text-success-700 bg-success-50 ring-success-600/20 dark:bg-success-950/40 dark:text-success-300 dark:ring-success-500/30',
        default => 'text-gray-700 bg-gray-50 ring-gray-600/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-500/30',
    };
@endphp

<div class="space-y-5">
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $statusColor }}">
                        {{ in_array($incident->status, ['healthy', 'unknown'], true) ? 'Recovered' : ucfirst($incident->status) }}
                    </span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Source row #{{ $incident->source_row_id }}
                    </span>
                </div>
                <h3 class="mt-2 truncate text-base font-semibold text-gray-950 dark:text-white">
                    {{ $incident->subject }}
                </h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $incident->summary ?: 'No summary was stored for this run.' }}
                </p>
            </div>

            @if ($targetUrl)
                <x-filament::button tag="a" :href="$targetUrl" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                    Open Resource
                </x-filament::button>
            @endif
        </div>
    </div>

    @if (! $evidence)
        <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-950/40 dark:text-warning-200">
            The source row for this incident is no longer available or is outside your access scope.
        </div>
    @elseif ($evidence instanceof WebsiteLogHistory)
        @php($scheduledFailureStreak = ScheduledFailureStreak::forWebsiteResult($evidence))
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-incident-feed-evidence-field label="Status" :value="ucfirst($evidence->status ?? 'unknown')" />
            <x-incident-feed-evidence-field label="Run" :value="RunSource::coerce($evidence->run_source)->label()" />
            <x-incident-feed-evidence-field label="HTTP" :value="$evidence->http_status_code === 0 ? 'No response' : ($evidence->http_status_code ?? '-')" />
            <x-incident-feed-evidence-field label="Response time" :value="$evidence->speed !== null ? $evidence->speed . 'ms' : '-'" />
            <x-incident-feed-evidence-field label="Captured at" :value="optional($evidence->created_at)->toDayDateTimeString() ?? '-'" />
            <x-incident-feed-evidence-field label="SSL expiry" :value="optional($evidence->ssl_expiry_date)->toFormattedDateString() ?? '-'" />
            <x-incident-feed-evidence-field label="Scheduled streak" :value="$scheduledFailureStreak['count'] > 0 ? $scheduledFailureStreak['count'] . ' failures' : '-'" />
            <x-incident-feed-evidence-field label="First failed at" :value="optional($scheduledFailureStreak['first_failed_at'])->toDayDateTimeString() ?? '-'" />
        </div>

        @if (filled($evidence->transport_error_type))
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Transport Evidence</h4>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <x-incident-feed-evidence-field label="Classification" :value="UptimeTransportError::label($evidence->transport_error_type)" />
                    <x-incident-feed-evidence-field label="cURL code" :value="$evidence->transport_error_code ?? '-'" />
                    <x-incident-feed-evidence-field label="Message" :value="$evidence->transport_error_message ?: '-'" class="sm:col-span-2" />
                </dl>
            </div>
        @endif
    @elseif ($evidence instanceof MonitorApiResult)
        @php($scheduledFailureStreak = ScheduledFailureStreak::forApiResult($evidence))
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-incident-feed-evidence-field label="Status" :value="ucfirst($evidence->status ?? ($evidence->is_success ? 'healthy' : 'danger'))" />
            <x-incident-feed-evidence-field label="Run" :value="RunSource::coerce($evidence->run_source)->label()" />
            <x-incident-feed-evidence-field label="HTTP" :value="$evidence->http_code === 0 ? 'No response' : ($evidence->http_code ?? '-')" />
            <x-incident-feed-evidence-field label="Response time" :value="$evidence->response_time_ms !== null ? $evidence->response_time_ms . 'ms' : '-'" />
            <x-incident-feed-evidence-field label="Captured at" :value="optional($evidence->created_at)->toDayDateTimeString() ?? '-'" />
            <x-incident-feed-evidence-field label="Failed assertions" :value="count($evidence->failed_assertions ?? [])" />
            <x-incident-feed-evidence-field label="Scheduled streak" :value="$scheduledFailureStreak['count'] > 0 ? $scheduledFailureStreak['count'] . ' failures' : '-'" />
            <x-incident-feed-evidence-field label="First failed at" :value="optional($scheduledFailureStreak['first_failed_at'])->toDayDateTimeString() ?? '-'" />
        </div>

        @if (filled($evidence->transport_error_type))
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Transport Evidence</h4>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <x-incident-feed-evidence-field label="Classification" :value="UptimeTransportError::label($evidence->transport_error_type)" />
                    <x-incident-feed-evidence-field label="cURL code" :value="$evidence->transport_error_code ?? '-'" />
                    <x-incident-feed-evidence-field label="Message" :value="$evidence->transport_error_message ?: '-'" class="sm:col-span-2" />
                </dl>
            </div>
        @endif

        @if (filled($evidence->failed_assertions))
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Failed Assertions</h4>
                <div class="mt-3 space-y-3">
                    @foreach (ApiMonitorEvidenceFormatter::normalizeAssertions($evidence->failed_assertions) as $assertion)
                        <div class="rounded-md border border-gray-200 p-3 text-sm dark:border-white/10">
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="rounded bg-danger-50 px-2 py-1 font-medium text-danger-700 dark:bg-danger-950/40 dark:text-danger-300">{{ $assertion['path'] }}</span>
                                <span class="rounded bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $assertion['type'] }}</span>
                            </div>
                            <p class="mt-2 text-gray-700 dark:text-gray-200">{{ $assertion['message'] }}</p>
                            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                                <x-incident-feed-evidence-field label="Expected" :value="$assertion['expected']" />
                                <x-incident-feed-evidence-field label="Actual" :value="$assertion['actual']" />
                            </dl>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (filled($evidence->response_body))
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Saved Failure Payload</h4>
                <div class="mt-3 max-h-96 overflow-auto rounded-md bg-gray-950 p-3 text-xs text-gray-100">
                    {!! ApiMonitorEvidenceFormatter::formatAsPreHtml(ApiMonitorEvidenceFormatter::formatPayload($evidence->response_body)) !!}
                </div>
            </div>
        @endif
    @elseif ($evidence instanceof ProjectComponentHeartbeat)
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-incident-feed-evidence-field label="Status" :value="ucfirst($evidence->status ?? 'unknown')" />
            <x-incident-feed-evidence-field label="Event" :value="ucfirst($evidence->event ?? 'unknown')" />
            <x-incident-feed-evidence-field label="Component" :value="$evidence->component_name" />
            <x-incident-feed-evidence-field label="Observed at" :value="optional($evidence->observed_at)->toDayDateTimeString() ?? '-'" />
            <x-incident-feed-evidence-field label="Recorded at" :value="optional($evidence->created_at)->toDayDateTimeString() ?? '-'" />
        </div>

        @if (filled($evidence->metrics))
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Metrics Snapshot</h4>
                <pre class="mt-3 max-h-80 overflow-auto rounded-md bg-gray-950 p-3 text-xs text-gray-100">{{ MetricsPayloadFormatter::format($evidence->metrics) }}</pre>
            </div>
        @endif
    @endif
</div>
