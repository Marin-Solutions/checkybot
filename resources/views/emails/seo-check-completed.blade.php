<x-mail::message>
    # SEO Health Check {{ $isScheduled ? 'Completed' : 'Results' }}

    Your {{ $isScheduled ? 'scheduled' : '' }} SEO health check for **{{ $website->name }}** has completed.

    ## Health Score: {{ number_format($healthScore, 1) }}%

    @if($scoreDiff !== null)
    @if($scoreDiff > 0)
    <x-mail::panel>
        <span style="color: #10b981; font-weight: bold;">↑ {{ number_format(abs($scoreDiff), 1) }}% improvement from last check</span>
    </x-mail::panel>
    @elseif($scoreDiff < 0)
        <x-mail::panel>
        <span style="color: #ef4444; font-weight: bold;">↓ {{ number_format(abs($scoreDiff), 1) }}% decline from last check</span>
        </x-mail::panel>
        @else
        <x-mail::panel>
            <span style="color: #6b7280;">→ No change from last check</span>
        </x-mail::panel>
        @endif
        @endif

        ## Issues Found

        <x-mail::table>
            | Severity | Count |
            | :------- | ----: |
            | **Errors** (Critical) | {{ $errorsCount }} |
            | **Warnings** (Medium) | {{ $warningsCount }} |
            | **Notices** (Low) | {{ $noticesCount }} |
        </x-mail::table>

        @if($errorsCount > 0)
        <x-mail::panel>
            ⚠️ **Action Required:** {{ $errorsCount }} critical SEO {{ $errorsCount === 1 ? 'error' : 'errors' }} detected that need immediate attention.
        </x-mail::panel>
        @endif

        @if($healthScore < 70)
            <x-mail::panel>
            📉 **Low Health Score:** Your website's SEO health score is below 70%. Review the issues in the report and take corrective action.
            </x-mail::panel>
            @endif

            <x-mail::button :url="$reportUrl">
                View Full Report
            </x-mail::button>

            **Website:** {{ $website->url }}
            **Crawled:** {{ $seoCheck->total_urls_crawled }} {{ $seoCheck->total_urls_crawled === 1 ? 'page' : 'pages' }}
            **Completed:** {{ $seoCheck->finished_at->format('M j, Y \a\t g:i A') }}

            Thanks,<br>
            {{ config('app.name') }}
</x-mail::message>