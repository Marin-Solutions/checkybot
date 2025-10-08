<?php

namespace App\Mail;

use App\Models\SeoCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SeoCheckCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public SeoCheck $seoCheck;

    public bool $isScheduled;

    public ?float $scoreDiff;

    /**
     * Create a new message instance.
     */
    public function __construct(SeoCheck $seoCheck, bool $isScheduled = false)
    {
        $this->seoCheck = $seoCheck;
        $this->isScheduled = $isScheduled;

        // Calculate score difference from previous check
        $previousCheck = SeoCheck::where('website_id', $seoCheck->website_id)
            ->where('status', 'completed')
            ->where('id', '!=', $seoCheck->id)
            ->orderBy('finished_at', 'desc')
            ->first();

        $this->scoreDiff = $previousCheck
            ? $seoCheck->computed_health_score - $previousCheck->computed_health_score
            : null;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $website = $this->seoCheck->website;
        $subject = $this->isScheduled
            ? "Scheduled SEO Check Completed - {$website->name}"
            : "SEO Health Check Completed - {$website->name}";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.seo-check-completed',
            with: [
                'seoCheck' => $this->seoCheck,
                'website' => $this->seoCheck->website,
                'healthScore' => $this->seoCheck->computed_health_score,
                'scoreDiff' => $this->scoreDiff,
                'errorsCount' => $this->seoCheck->computed_errors_count,
                'warningsCount' => $this->seoCheck->computed_warnings_count,
                'noticesCount' => $this->seoCheck->computed_notices_count,
                'reportUrl' => url("/admin/seo-checks/{$this->seoCheck->id}"),
                'isScheduled' => $this->isScheduled,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
