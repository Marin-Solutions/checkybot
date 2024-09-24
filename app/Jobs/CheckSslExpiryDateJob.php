<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\SslCertificate\SslCertificate;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckSslExpiryDateJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(Collection $website): void
    {
        $certificate = SslCertificate::createForHostName($website['url']);
        $expiryDate = $certificate->expirationDate();

        $dateExpiryWebsite = Carbon::parse($website['ssl_expiry_date']);
        $expiryDate = Carbon::parse($expiryDate);
        if( $expiryDate->gt($dateExpiryWebsite) ){
            Website::whereId($website['id'])->update(['ssl_expiry_date' =>  $expiryDate]);
        }else{
            //'SEND REMINDERS';
            $checkDay = "";
        }

    }
}
