<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\User;


use App\Models\Website;
use App\Mail\EmailReminderSsl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\SslCertificate\SslCertificate;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckSslExpiryDateJob implements ShouldQueue
{
    use Queueable;

    protected $website;

    public function __construct(array $websites)
    {
        $this->website= $websites;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $website = $this->website;
        $certificate = SslCertificate::createForHostName($website['url']);
        $expiryDate = $certificate->expirationDate();

        $dateExpiryWebsite = Carbon::parse($website['ssl_expiry_date']);
        $expiryDate = Carbon::parse($expiryDate);
        if( !$expiryDate->gt($dateExpiryWebsite) ){
            Website::whereId($website['id'])->update(['ssl_expiry_date' =>  $expiryDate]);
        }else{
            //'SEND REMINDERS';
            $user = User::find(Website::whereId($website['id'])->get(['created_by']));
            $daysLeft = $website['days_left'];
            $emailData=[
                'user' => $user,
                'daysLeft' => $daysLeft,
                'url' => $website['url']
            ];
            Mail::to($user)->send( new EmailReminderSsl($emailData));
        }

    }
}
