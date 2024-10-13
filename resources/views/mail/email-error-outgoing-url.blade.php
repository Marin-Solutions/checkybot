<h1>Outgoing Link Error Notification</h1>

<p>Dear {{ $user }},</p>

<p>
    Hello, the webpage {{$url}} points to a page that shows a {{$http_status_code}} error: {{$outgoing_url}}
</p>

<p>Best regards, Your team {{ config('app.name') }}</p>
