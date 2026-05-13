<x-mail::message>
# {{ $payload['title'] ?? 'Application component alert' }}

{{ $payload['message'] ?? 'A monitored application component requires attention.' }}

@if (! empty($payload['summary']))
{{ $payload['summary'] }}
@elseif (! empty($payload['details']))
{{ $payload['details'] }}
@endif

@if (! empty($payload['evidence']))
@foreach ($payload['evidence'] as $label => $value)
@if ($label === 'Metrics')
**{{ $label }}**

<pre><code>{{ $value }}</code></pre>
@else
**{{ $label }}:** {{ $value }}
@endif

@endforeach
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
