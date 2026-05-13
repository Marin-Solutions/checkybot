<x-mail::message>
# {{ $payload['title'] ?? 'Application component alert' }}

{{ $payload['message'] ?? 'A monitored application component requires attention.' }}

@if (! empty($payload['summary']))
{{ $payload['summary'] }}
@endif

@if (! empty($payload['evidence']))
@foreach ($payload['evidence'] as $item)
@if (($item['type'] ?? 'text') === 'code')
**{{ $item['label'] }}**

<pre><code>{{ $item['value'] }}</code></pre>
@else
**{{ $item['label'] }}:** {{ $item['value'] }}
@endif

@endforeach
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
