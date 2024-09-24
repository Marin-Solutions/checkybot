<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;

class EmailReminderSsl extends Mailable
{
    use Queueable, SerializesModels;

    protected $emailData;

    public function __construct($emailData)
    {
        $this->emailData = $emailData;
    }

    public function build()
    {
        return $this->subject('Action Required: Renew Your SSL Certificate.')
            ->view('mails.reminder-ssl')
            ->with([
                'user' => $this->emailData['user'][0]->name,
                'daysLeft' => $this->emailData['daysLeft'],
                'url' => $this->emailData['url'],
            ]);
    }
}
