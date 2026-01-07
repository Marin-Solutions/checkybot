<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailReminderSsl extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{user: \App\Models\User, daysLeft: int, url: string}  $emailData
     */
    public array $emailData;

    public function __construct(array $emailData)
    {
        $this->emailData = $emailData;
    }

    public function build()
    {
        return $this->subject('Action Required: Renew Your SSL Certificate.')
            ->view('mails.reminder-ssl')
            ->with([
                'user' => $this->emailData['user']->name,
                'daysLeft' => $this->emailData['daysLeft'],
                'url' => $this->emailData['url'],
            ]);
    }
}
