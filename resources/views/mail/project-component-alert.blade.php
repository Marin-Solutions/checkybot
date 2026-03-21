<x-mail::message>
# {{ $payload['title'] ?? 'Application component alert' }}

{{ $payload['message'] ?? 'A monitored application component requires attention.' }}

@if (! empty($payload['details']))
{{ $payload['details'] }}
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
