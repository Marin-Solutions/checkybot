<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HealthStatusAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $event,
        public string $status,
        public string $summary,
        public ?string $url = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Health Status Alert: {$this->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.health-status-alert',
            with: [
                'name' => $this->name,
                'event' => $this->event,
                'status' => $this->status,
                'summary' => $this->summary,
                'url' => $this->url,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
