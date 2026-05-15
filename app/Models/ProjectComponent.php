<?php

namespace App\Models;

use App\Models\Concerns\HasSnooze;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectComponent extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectComponentFactory> */
    use HasFactory, HasSnooze;

    public const ADMIN_DISABLED_SUMMARY = 'Disabled in Checkybot admin.';

    public const ARCHIVE_REASON_PACKAGE = 'package';

    public const ARCHIVE_REASON_USER = 'user';

    protected $fillable = [
        'project_id',
        'name',
        'summary',
        'source',
        'declared_interval',
        'interval_minutes',
        'current_status',
        'last_reported_status',
        'metrics',
        'last_heartbeat_at',
        'stale_detected_at',
        'is_stale',
        'is_archived',
        'project_paused_monitoring',
        'archived_at',
        'archive_reason',
        'silenced_until',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'last_heartbeat_at' => 'datetime',
            'stale_detected_at' => 'datetime',
            'is_stale' => 'boolean',
            'is_archived' => 'boolean',
            'project_paused_monitoring' => 'boolean',
            'archived_at' => 'datetime',
            'silenced_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProjectComponent $component): void {
            if ($component->exists && $component->isDirty('is_archived') && $component->project_paused_monitoring) {
                $component->project_paused_monitoring = false;
            }
        });
    }

    public static function disabledHealthAttributes(?string $summary = self::ADMIN_DISABLED_SUMMARY): array
    {
        return [
            'current_status' => 'unknown',
            'last_reported_status' => 'unknown',
            'summary' => $summary,
            'last_heartbeat_at' => null,
            'stale_detected_at' => null,
            'is_stale' => false,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(ProjectComponentHeartbeat::class)->latest('observed_at');
    }

    public function monitorApis(): HasMany
    {
        return $this->hasMany(MonitorApis::class, 'project_component_id');
    }

    public function activeMonitorApis(): HasMany
    {
        return $this->monitorApis()->where('is_enabled', true);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class, 'project_component_id');
    }

    public function activeWebsites(): HasMany
    {
        return $this->websites()
            ->where(function ($query): void {
                $query
                    ->where('uptime_check', true)
                    ->orWhere('ssl_check', true);
            });
    }

    public function latestHeartbeat(): HasOne
    {
        return $this->hasOne(ProjectComponentHeartbeat::class)->ofMany(
            ['observed_at' => 'max', 'id' => 'max'],
        );
    }

    public function notificationSettings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'project_component_id')->projectComponentScope();
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'project_component_id')->projectComponentScope()->active();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function derivedCurrentStatus(): string
    {
        if ((bool) $this->is_archived) {
            return 'unknown';
        }

        $statuses = $this->activeChildStatuses();

        if ($statuses === []) {
            return 'pending';
        }

        return collect($statuses)
            ->sortByDesc(fn (string $status): int => $this->statusPriority($status))
            ->first() ?? 'pending';
    }

    public function derivedStatusSummary(): string
    {
        $status = $this->derivedCurrentStatus();

        if ($status === 'pending') {
            return 'Awaiting first active child check result.';
        }

        return $this->summary ?? match ($status) {
            'healthy' => 'All active child checks are healthy.',
            'warning' => 'At least one active child check is warning.',
            'danger' => 'At least one active child check is failing.',
            default => 'No active child check result is available.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function activeChildStatuses(): array
    {
        $apis = $this->relationLoaded('activeMonitorApis')
            ? $this->activeMonitorApis
            : $this->activeMonitorApis()->get(['current_status']);

        $websites = $this->relationLoaded('activeWebsites')
            ? $this->activeWebsites
            : $this->activeWebsites()->get(['current_status']);

        return $apis
            ->map(fn (MonitorApis $api): string => $this->checkStatus($api))
            ->merge($websites->map(fn (Website $website): string => $this->checkStatus($website)))
            ->all();
    }

    private function checkStatus(MonitorApis|Website $check): string
    {
        if (in_array($check->current_status, ['healthy', 'warning', 'danger'], true)) {
            return $check->current_status;
        }

        return 'pending';
    }

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'danger' => 3,
            'warning' => 2,
            'pending', 'unknown' => 1,
            'healthy' => 0,
            default => 1,
        };
    }
}
