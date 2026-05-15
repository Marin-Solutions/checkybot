<?php

namespace App\Models;

use App\Models\Concerns\HasSnooze;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectComponent extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectComponentFactory> */
    use HasFactory, HasSnooze;

    public const ADMIN_DISABLED_SUMMARY = 'Disabled in Checkybot admin.';

    public const ARCHIVE_REASON_PACKAGE = 'package';

    public const ARCHIVE_REASON_USER = 'user';

    private const REMOVED_HEARTBEAT_ATTRIBUTES = [
        'last_heartbeat_at',
        'stale_detected_at',
        'is_stale',
    ];

    private ?string $cachedDerivedStatus = null;

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

    public function setAttribute($key, $value): mixed
    {
        if (in_array($key, self::REMOVED_HEARTBEAT_ATTRIBUTES, true)) {
            return $this;
        }

        if (in_array($key, ['current_status', 'is_archived', 'silenced_until'], true)) {
            $this->cachedDerivedStatus = null;
        }

        return parent::setAttribute($key, $value);
    }

    public static function disabledHealthAttributes(?string $summary = self::ADMIN_DISABLED_SUMMARY): array
    {
        return [
            'current_status' => 'unknown',
            'last_reported_status' => 'unknown',
            'summary' => $summary,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
        if ($this->cachedDerivedStatus !== null) {
            return $this->cachedDerivedStatus;
        }

        if ((bool) $this->is_archived) {
            return $this->cachedDerivedStatus = 'unknown';
        }

        $statuses = $this->activeChildStatuses();

        if ($statuses === []) {
            if ($this->source !== 'package' && in_array($this->current_status, ['healthy', 'warning', 'danger'], true)) {
                return $this->cachedDerivedStatus = $this->current_status;
            }

            return $this->cachedDerivedStatus = 'pending';
        }

        return $this->cachedDerivedStatus = collect($statuses)
            ->sortByDesc(fn (string $status): int => $this->statusPriority($status))
            ->first() ?? 'pending';
    }

    public function derivedStatusSummary(): string
    {
        $status = $this->derivedCurrentStatus();
        $storedSummary = $this->summary;

        if ($status === 'pending') {
            return 'Awaiting first active child check result.';
        }

        if ($storedSummary !== null && ! in_array($storedSummary, [
            'Awaiting active child check results',
            'Awaiting first active child check result.',
        ], true)) {
            return $storedSummary;
        }

        return match ($status) {
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
