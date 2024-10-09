<?php

    namespace App\Console\Commands;

    use App\Jobs\LogUptimeSslJob;
    use App\Models\Website;
    use Illuminate\Console\Command;

    class LogJobCheckUptimeSsl extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'website:log-uptime-ssl';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Log website uptime and SSL';

        /**
         * Execute the console command.
         */
        public function handle()
        {
            $websites = Website::all();

            if ($websites->isNotEmpty()) {
                $websites->each(function ($website) {
                    LogUptimeSslJob::dispatch($website)->onQueue('log-website');
                });
            }

            $this->info('Websites Logging is in progress');
            return Command::SUCCESS;
        }
    }
