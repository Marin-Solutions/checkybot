<?php

namespace App\Console\Commands;

use App\Models\ErrorReports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Optimize table (MySQL only)
        if (!$dryRun && $this->isMySQL()) {
            $this->info("Optimizing table structure...");
            DB::statement('OPTIMIZE TABLE error_reports');
            $this->info("Table optimization complete!");
        }

        return 0;
    }

    private function analyzeTable()
    {
        $this->info("Analyzing error_reports table...");

        $dbDriver = config('database.default');
        $connection = config("database.connections.{$dbDriver}.driver");

        $this->info("Database: {$connection}");

        // Get record counts by project
        $projectCounts = ErrorReports::selectRaw('
            project_id, 
            COUNT(*) as count,
            MIN(created_at) as oldest,
            MAX(created_at) as newest
        ')
            ->groupBy('project_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Get most common exception classes
        $exceptionClasses = ErrorReports::selectRaw('
            exception_class, 
            COUNT(*) as count 
        ')
            ->whereNotNull('exception_class')
            ->groupBy('exception_class')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Basic metrics
        $totalRecords = ErrorReports::count();
        $recentRecords7Days = ErrorReports::where('created_at', '>=', now()->subDays(7))->count();
        $recentRecords30Days = ErrorReports::where('created_at', '>=', now()->subDays(30))->count();

        $this->table(['Metric', 'Value'], [
            ['Database Type', $connection],
            ['Total Records', number_format($totalRecords)],
            ['Records Last 7 Days', number_format($recentRecords7Days)],
            ['Records Last 30 Days', number_format($recentRecords30Days)],
        ]);

        $this->info("\n📊 Top Projects by Error Count:");
        $this->table(
            ['Project ID', 'Error Count', 'Oldest Error', 'Newest Error'],
            $projectCounts->map(fn($p) => [$p->project_id, number_format($p->count), $p->oldest, $p->newest])->toArray()
        );

        $this->info("\n🔥 Most Common Exception Classes:");
        $this->table(
            ['Exception Class', 'Count'],
            $exceptionClasses->map(fn($e) => [$e->exception_class, number_format($e->count)])->toArray()
        );

        // Check for indexes (MySQL only)
        if ($this->isMySQL()) {
            try {
                $indexes = DB::select("SHOW INDEX FROM error_reports WHERE Key_name != 'PRIMARY'");

                $this->info("\n🗂️ Current Indexes:");
                if (empty($indexes)) {
                    $this->warn("❌ No indexes found! Run the migration to add performance indexes.");
                } else {
                    $this->table(
                        ['Index Name', 'Column', 'Unique'],
                        array_map(fn($i) => [$i->Key_name, $i->Column_name, $i->Non_unique ? 'No' : 'Yes'], $indexes)
                    );
                }
            } catch (\Exception $e) {
                $this->warn("Could not retrieve index information: " . $e->getMessage());
            }
        } else {
            $this->info("\n🗂️ Index Information:");
            $this->warn("Index analysis not available for {$connection} database.");
        }

        $this->info("\n💡 Recommendations:");
        if ($this->isMySQL()) {
            $this->line("• Run 'php artisan migrate' to add performance indexes (MySQL)");
            $this->line("• Consider keeping only last 30-90 days of error reports");
            $this->line("• Archive old data to separate storage if needed for compliance");
            $this->line("• Monitor table size and run optimization regularly");
        } else {
            $this->line("• For production MySQL: Run 'php artisan migrate' to add performance indexes");
            $this->line("• Consider keeping only last 30-90 days of error reports");
            $this->line("• Archive old data to separate storage if needed for compliance");
        }

        if ($totalRecords > 100000) {
            $this->warn("\n⚠️  Large dataset detected ({$totalRecords} records)");
            $this->line("Consider running: php artisan error-reports:optimize --days=30 --dry-run");
        }
    }

    private function isMySQL(): bool
    {
        $driver = config('database.connections.' . config('database.default') . '.driver');
        return $driver === 'mysql';
    }
}
