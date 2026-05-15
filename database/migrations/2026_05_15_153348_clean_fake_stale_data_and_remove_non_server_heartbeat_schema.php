<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->deleteSyntheticStaleResults();
        $this->recalculateApiHealth();
        $this->recalculateWebsiteHealth();
        $this->recalculateComponentHealth();
        $this->dropNonServerHeartbeatSchema();
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('current_status');
            }

            if (! Schema::hasColumn('websites', 'awaiting_heartbeat_since')) {
                $table->timestamp('awaiting_heartbeat_since')->nullable()->after('last_heartbeat_at');
            }

            if (! Schema::hasColumn('websites', 'stale_at')) {
                $table->timestamp('stale_at')->nullable()->after('awaiting_heartbeat_since');
            }
        });

        Schema::table('monitor_apis', function (Blueprint $table): void {
            if (! Schema::hasColumn('monitor_apis', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('current_status');
            }

            if (! Schema::hasColumn('monitor_apis', 'awaiting_heartbeat_since')) {
                $table->timestamp('awaiting_heartbeat_since')->nullable()->after('last_heartbeat_at');
            }

            if (! Schema::hasColumn('monitor_apis', 'stale_at')) {
                $table->timestamp('stale_at')->nullable()->after('awaiting_heartbeat_since');
            }
        });

        Schema::table('project_components', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_components', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('metrics');
            }

            if (! Schema::hasColumn('project_components', 'stale_detected_at')) {
                $table->timestamp('stale_detected_at')->nullable()->after('last_heartbeat_at');
            }

            if (! Schema::hasColumn('project_components', 'is_stale')) {
                $table->boolean('is_stale')->default(false)->after('stale_detected_at');
            }
        });

        if (! Schema::hasTable('project_component_heartbeats')) {
            Schema::create('project_component_heartbeats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_component_id')->constrained('project_components')->cascadeOnDelete();
                $table->string('component_name');
                $table->string('status', 20);
                $table->string('event', 20);
                $table->text('summary')->nullable();
                $table->json('metrics')->nullable();
                $table->timestamp('observed_at');
                $table->timestamps();

                $table->index(['project_component_id', 'observed_at']);
                $table->index(['component_name', 'event']);
            });
        }
    }

    private function deleteSyntheticStaleResults(): void
    {
        DB::table('monitor_api_results')
            ->whereIn('summary', $this->syntheticStaleSummaries())
            ->delete();

        DB::table('website_log_history')
            ->whereIn('summary', $this->syntheticStaleSummaries())
            ->delete();

        if (Schema::hasTable('project_component_heartbeats')) {
            DB::table('project_component_heartbeats')
                ->where('event', 'stale')
                ->orWhereIn('summary', $this->syntheticStaleSummaries())
                ->delete();
        }

        foreach (['notification_settings', 'notification_channels'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'last_delivery_summary')) {
                continue;
            }

            DB::table($table)
                ->whereIn('last_delivery_summary', $this->syntheticStaleSummaries())
                ->update([
                    'last_delivery_kind' => null,
                    'last_delivery_succeeded' => null,
                    'last_delivery_response_code' => null,
                    'last_delivery_summary' => null,
                    'last_delivery_attempted_at' => null,
                ]);
        }
    }

    private function recalculateApiHealth(): void
    {
        DB::table('monitor_apis')
            ->select(['id'])
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $api): void {
                $latest = DB::table('monitor_api_results')
                    ->where('monitor_api_id', $api->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first(['status', 'summary', 'is_success', 'http_code']);

                DB::table('monitor_apis')
                    ->where('id', $api->id)
                    ->update([
                        'current_status' => $this->statusFromApiResult($latest),
                        'status_summary' => $latest?->summary,
                    ]);
            });
    }

    private function recalculateWebsiteHealth(): void
    {
        DB::table('websites')
            ->select(['id'])
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $website): void {
                $latest = DB::table('website_log_history')
                    ->where('website_id', $website->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first(['status', 'summary', 'http_status_code', 'transport_error_type']);

                DB::table('websites')
                    ->where('id', $website->id)
                    ->update([
                        'current_status' => $this->statusFromWebsiteResult($latest),
                        'status_summary' => $latest?->summary,
                    ]);
            });
    }

    private function recalculateComponentHealth(): void
    {
        DB::table('project_components')
            ->select(['id', 'is_archived'])
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $component): void {
                $statuses = DB::table('monitor_apis')
                    ->where('project_component_id', $component->id)
                    ->where('is_enabled', true)
                    ->pluck('current_status')
                    ->merge(
                        DB::table('websites')
                            ->where('project_component_id', $component->id)
                            ->where(function ($query): void {
                                $query->where('uptime_check', true)
                                    ->orWhere('ssl_check', true);
                            })
                            ->pluck('current_status')
                    )
                    ->map(fn (?string $status): string => in_array($status, ['healthy', 'warning', 'danger'], true) ? $status : 'pending');

                $status = match (true) {
                    (bool) $component->is_archived => 'unknown',
                    $statuses->isEmpty() => 'pending',
                    $statuses->contains('danger') => 'danger',
                    $statuses->contains('warning') => 'warning',
                    $statuses->contains('pending') => 'pending',
                    default => 'healthy',
                };

                DB::table('project_components')
                    ->where('id', $component->id)
                    ->update([
                        'current_status' => $status,
                        'last_reported_status' => $status === 'unknown' ? 'unknown' : $status,
                        'summary' => $status === 'pending' ? 'Awaiting first active child check result.' : null,
                    ]);
            });
    }

    private function dropNonServerHeartbeatSchema(): void
    {
        Schema::dropIfExists('project_component_heartbeats');

        Schema::table('project_components', function (Blueprint $table): void {
            if (Schema::hasIndex('project_components', 'project_components_stale_last_heartbeat_index')) {
                $table->dropIndex('project_components_stale_last_heartbeat_index');
            }

            if (Schema::hasIndex('project_components', 'project_components_stale_created_index')) {
                $table->dropIndex('project_components_stale_created_index');
            }
        });

        Schema::table('websites', function (Blueprint $table): void {
            if (Schema::hasIndex('websites', 'websites_ssl_package_due_idx')) {
                $table->dropIndex('websites_ssl_package_due_idx');
            }
        });

        $this->dropColumnsIfPresent('websites', ['last_heartbeat_at', 'awaiting_heartbeat_since', 'stale_at']);
        $this->dropColumnsIfPresent('monitor_apis', ['last_heartbeat_at', 'awaiting_heartbeat_since', 'stale_at']);
        $this->dropColumnsIfPresent('project_components', ['last_heartbeat_at', 'stale_detected_at', 'is_stale']);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }

    /**
     * @return array<int, string>
     */
    private function syntheticStaleSummaries(): array
    {
        return [
            'Heartbeat expired',
            'Heartbeat expired.',
            'Package check heartbeat expired.',
            'Package heartbeat expired.',
            'Scheduled heartbeat expired.',
        ];
    }

    private function statusFromApiResult(?object $result): string
    {
        if ($result === null) {
            return 'pending';
        }

        if (in_array($result->status, ['healthy', 'warning', 'danger'], true)) {
            return $result->status;
        }

        return (bool) $result->is_success ? 'healthy' : 'danger';
    }

    private function statusFromWebsiteResult(?object $result): string
    {
        if ($result === null) {
            return 'pending';
        }

        if (in_array($result->status, ['healthy', 'warning', 'danger'], true)) {
            return $result->status;
        }

        if ($result->transport_error_type !== null || ((int) ($result->http_status_code ?? 200)) >= 400) {
            return 'danger';
        }

        return 'healthy';
    }
};
