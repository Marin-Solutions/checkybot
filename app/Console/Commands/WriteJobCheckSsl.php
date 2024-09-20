<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\Website;
use Illuminate\Console\Command;
use App\Jobs\CheckSslExpiryDateJob;
use Illuminate\Support\Collection;

class WriteJobCheckSsl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ssl:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check SSL certificates and send reminders';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websites = $this->sslExpiryDay();

        echo $websites;


        //$this->info("You writted this $websitesExpireIn14");
        // CheckSslExpiryDateJob::dispatch($websitesExpire)->onQueue('14 Days');
        // CheckSslExpiryDateJob::dispatch($websitesExpire)->onQueue('7 Days');
        // CheckSslExpiryDateJob::dispatch($websitesExpire)->onQueue('5 Days');
        return Command::SUCCESS;
    }

    protected function sslExpiryDay(): Collection
     {
        $days = [14,7,3,2,1];
        $websites = Website::where('ssl_check','1')
            ->get(['id','url','ssl_expiry_date'])
            ->map(function(Website $web) use ($days){
                $dateBd = Carbon::parse($web->ssl_expiry_date);
                $now = Carbon::today();
                $diffInDays = (int)$now->diffInDays($dateBd);

                if (in_array($diffInDays, $days)) {
                    return ['id'=>$web->id,'url'=>$web->url,'ssl_expiry_date'=>$web->ssl_expiry_date,'check'=>1,'expired'=>0,'days_left'=>$diffInDays];
                }else if($diffInDays <0){
                    return  ['id'=>$web->id,'url'=>$web->url,'ssl_expiry_date'=>$web->ssl_expiry_date,'check'=>0,'expired'=>1];
                }

            })->filter();

        return $websites;
    }

}
