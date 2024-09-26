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
        $this->website = $websites;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $website = $this->website;
        $certificate = SslCertificate::createForHostName($website['url']);
        $newExpiryDate = $certificate->expirationDate();
        $currentExpiryDate = Carbon::parse($website['ssl_expiry_date']);

        if ($newExpiryDate->gt($currentExpiryDate)) {
            Website::where('id', $website['id'])->update(['ssl_expiry_date' => $newExpiryDate]);
            return;
        }

        $user = User::find(Website::where('id', $website['id'])->value('created_by'));

        if ($user) {
            $emailData = [
                'user' => $user,
                'daysLeft' => $website['days_left'],
                'url' => $website['url']
            ];

            Mail::to($user)->send(new EmailReminderSsl($emailData));

            Log::info("SSL expiry reminder sent for website: {$website['url']}");
        } else {
            Log::warning("User not found for website: {$website['url']}");
        }
    }
}
