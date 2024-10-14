<?php

    namespace App\Jobs;

    use App\Models\WebsiteLogHistory;
    use Carbon\Carbon;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Queue\Queueable;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Spatie\SslCertificate\SslCertificate;

    class LogUptimeSslJob implements ShouldQueue
    {
        use Queueable;

        protected $website;

        /**
         * Create a new job instance.
         */
        public function __construct( $website )
        {
            $this->website = $website;
        }

        /**
         * Execute the job.
         */
        public function handle(): void
        {
            $log = new WebsiteLogHistory;

            /*Get SSL expiry date*/
            $certificate          = SslCertificate::createForHostName($this->website[ 'url' ]);
            $log->ssl_expiry_date = $certificate->expirationDate();

            /*Hit Website to get status code & speed*/
            $responseTimeStart = Carbon::now();
            $response          = Http::get($this->website['url']);
            $responseTimeEnd   = Carbon::now();
            $log->http_status_code = $response->status();
            $log->speed            = $responseTimeEnd->diffInMilliseconds($responseTimeStart);

            /*Save log*/
            $log->save();

            /*Create system log*/
            Log::info('Log created for website ' . $this->website[ 'url' ]);
        }
    }
