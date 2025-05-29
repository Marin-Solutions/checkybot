<?php

namespace App\Console\Commands;

use App\Models\ErrorReports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OptimizeErrorReportsTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'error-reports:optimize
                            {--days=30 : Keep only error reports from the last N days}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--analyze : Analyze table and suggest optimizations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize the error_reports table by cleaning old records and analyzing performance';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $analyze = $this->option('analyze');

        if ($analyze) {
            $this->analyzeTable();
            return 0;
        }

        $this->info("Optimizing error_reports table...");

        // Get count of records older than specified days
        $cutoffDate = now()->subDays($days);
        $oldRecordsCount = ErrorReports::where('created_at', '<', $cutoffDate)->count();
        $totalRecords = ErrorReports::count();

        $this->info("Total records: {$totalRecords}");
        $this->info("Records older than {$days} days: {$oldRecordsCount}");

        if ($oldRecordsCount > 0) {
            if ($dryRun) {
                $this->warn("[DRY RUN] Would delete {$oldRecordsCount} records older than {$days} days");
            } else {
                if ($this->confirm("Delete {$oldRecordsCount} records older than {$days} days?")) {
                    $this->info("Deleting old records...");

                    // Delete in chunks to avoid memory issues
                    $deleted = 0;
                    $chunkSize = 1000;

                    do {
                        $deletedChunk = ErrorReports::where('created_at', '<', $cutoffDate)
                            ->limit($chunkSize)
                            ->delete();

                        $deleted += $deletedChunk;
                        $this->info("Deleted {$deleted} records...");

                        // Give the database a breather
                        usleep(100000); // 100ms

                    } while ($deletedChunk > 0);

                    $this->info("Deleted {$deleted} old records successfully!");
                }
            }
        }

        // Optimize table
        if (!$dryRun) {
            $this->info("Optimizing table structure...");
            DB::statement('OPTIMIZE TABLE error_reports');
            $this->info("Table optimization complete!");
        }

        return 0;
    }

    private function analyzeTable()
    {
        $this->info("Analyzing error_reports table...");

        // Get table size
        $tableSize = DB::selectOne("
            SELECT 
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'error_reports'
        ");

        // Get record counts by project
        $projectCounts = DB::select("
            SELECT 
                project_id, 
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            FROM error_reports 
            GROUP BY project_id 
            ORDER BY count DESC 
            LIMIT 10
        ");

        // Get most common exception classes
        $exceptionClasses = DB::select("
            SELECT 
                exception_class, 
                COUNT(*) as count 
            FROM error_reports 
            WHERE exception_class IS NOT NULL
            GROUP BY exception_class 
            ORDER BY count DESC 
            LIMIT 10
        ");

        // Check for indexes
        $indexes = DB::select("SHOW INDEX FROM error_reports WHERE Key_name != 'PRIMARY'");

        $this->table(['Metric', 'Value'], [
            ['Table Size', ($tableSize->size_mb ?? 0) . ' MB'],
            ['Total Records', ErrorReports::count()],
            ['Records Last 7 Days', ErrorReports::where('created_at', '>=', now()->subDays(7))->count()],
            ['Records Last 30 Days', ErrorReports::where('created_at', '>=', now()->subDays(30))->count()],
        ]);

        $this->info("\n📊 Top Projects by Error Count:");
        $this->table(
            ['Project ID', 'Error Count', 'Oldest Error', 'Newest Error'],
            array_map(fn($p) => [$p->project_id, $p->count, $p->oldest, $p->newest], $projectCounts)
        );

        $this->info("\n🔥 Most Common Exception Classes:");
        $this->table(
            ['Exception Class', 'Count'],
            array_map(fn($e) => [$e->exception_class, $e->count], $exceptionClasses)
        );

        $this->info("\n🗂️ Current Indexes:");
        if (empty($indexes)) {
            $this->warn("❌ No indexes found! Run the migration to add performance indexes.");
        } else {
            $this->table(
                ['Index Name', 'Column', 'Unique'],
                array_map(fn($i) => [$i->Key_name, $i->Column_name, $i->Non_unique ? 'No' : 'Yes'], $indexes)
            );
        }

        $this->info("\n💡 Recommendations:");
        $this->line("• Run 'php artisan migrate' to add performance indexes");
        $this->line("• Consider keeping only last 30-90 days of error reports");
        $this->line("• Archive old data to separate storage if needed for compliance");
        $this->line("• Monitor table size and run optimization regularly");
    }
}
