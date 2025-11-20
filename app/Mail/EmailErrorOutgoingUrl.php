<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailErrorOutgoingUrl extends Mailable
{
    use Queueable, SerializesModels;

    protected $mailData;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
    }

    public function build()
    {
        return $this->subject('Outgoing Link Error Notification')
            ->view('mail.email-error-outgoing-url')
            ->with([
                'user' => $this->mailData['user']->name,
                'url' => $this->mailData['found_on'],
                'outgoing_url' => $this->mailData['outgoing_url'],
                'http_status_code' => $this->mailData['http_status_code'],
            ]);
    }
}
