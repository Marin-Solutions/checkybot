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

        protected array $intervals = [1, 5, 10, 15, 30, 60, 360, 720, 1440];

        /**
         * Execute the console command.
         */
        public function handle()
        {
            $currentMinute = now()->minute;
            $matchingIntervals = array_filter($this->intervals, function($interval) use ($currentMinute) {
                return $currentMinute % $interval === 0;
            });

            if (empty($matchingIntervals)) {
                $this->info('No intervals match the current minute');
                return Command::SUCCESS;
            }

            $websites = Website::where('uptime_check', true)
                ->whereIn('uptime_interval', $matchingIntervals)
                ->get();

            if ($websites->isNotEmpty()) {
                $websites->each(function ($website) {
                    LogUptimeSslJob::dispatch($website)->onQueue('log-website');
                });
                $this->info("Processing " . $websites->count() . " websites for intervals: " . implode(', ', $matchingIntervals));
            }

            return Command::SUCCESS;
        }
    }
