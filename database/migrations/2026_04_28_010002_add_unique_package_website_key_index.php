<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicatePackageWebsites();

        Schema::table('websites', function (Blueprint $table) {
            if ($this->indexExists('websites', 'idx_websites_project_source_name')) {
                $table->dropIndex('idx_websites_project_source_name');
            }
        });

        if (! $this->indexExists('websites', 'websites_project_source_package_unique')) {
            $this->createUniquePackageIndex();
        }
    }

    public function down(): void
    {
        if ($this->indexExists('websites', 'websites_project_source_package_unique')) {
            $this->dropUniquePackageIndex();
        }

        Schema::table('websites', function (Blueprint $table) {
            if (! $this->indexExists('websites', 'idx_websites_project_source_name')) {
                $table->index(['project_id', 'source', 'package_name'], 'idx_websites_project_source_name');
            }
        });
    }

    protected function deduplicatePackageWebsites(): void
    {
        $duplicates = DB::table('websites')
            ->select('project_id', 'source', 'package_name')
            ->where('source', 'package')
            ->whereNotNull('package_name')
            ->groupBy('project_id', 'source', 'package_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keepId = DB::table('websites')
                ->where('project_id', $duplicate->project_id)
                ->where('source', $duplicate->source)
                ->where('package_name', $duplicate->package_name)
                ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->value('id');

            if ($keepId === null) {
                continue;
            }

            $duplicateIds = DB::table('websites')
                ->where('project_id', $duplicate->project_id)
                ->where('source', $duplicate->source)
                ->where('package_name', $duplicate->package_name)
                ->where('id', '!=', $keepId)
                ->pluck('id');

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            $this->reassignWebsiteReferences($duplicateIds->all(), (int) $keepId);

            DB::table('websites')
                ->whereIn('id', $duplicateIds->all())
                ->delete();
        }
    }

    /**
     * @param  array<int, int>  $duplicateIds
     */
    protected function reassignWebsiteReferences(array $duplicateIds, int $keepId): void
    {
        // These tables either have foreign keys to websites or website-scoped references with non-unique indexes only.
        foreach (['website_log_history', 'outbound_link', 'seo_checks', 'seo_schedules', 'notification_settings'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'website_id')) {
                continue;
            }

            DB::table($table)
                ->whereIn('website_id', $duplicateIds)
                ->update(['website_id' => $keepId]);
        }
    }

    protected function createUniquePackageIndex(): void
    {
        if ($this->supportsFilteredIndexes()) {
            DB::statement(
                "CREATE UNIQUE INDEX websites_project_source_package_unique ON websites (project_id, source, package_name) WHERE source = 'package' AND package_name IS NOT NULL"
            );

            return;
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->unique(['project_id', 'source', 'package_name'], 'websites_project_source_package_unique');
        });
    }

    protected function dropUniquePackageIndex(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX websites_project_source_package_unique ON websites');

            return;
        }

        if ($this->supportsFilteredIndexes()) {
            DB::statement('DROP INDEX websites_project_source_package_unique');

            return;
        }

        Schema::table('websites', function (Blueprint $table) {
            if ($this->indexExists('websites', 'websites_project_source_package_unique')) {
                $table->dropUnique('websites_project_source_package_unique');
            }
        });
    }

    protected function supportsFilteredIndexes(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite', 'sqlsrv'], true);
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }
};
