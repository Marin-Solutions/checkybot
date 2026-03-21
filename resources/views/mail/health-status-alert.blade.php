<x-mail::message>
# Health Status Alert

`{{ $name }}` reported a `{{ $status }}` event.

{{ $summary }}

@if ($url)
<x-mail::button :url="$url">
Open Monitored Item
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
