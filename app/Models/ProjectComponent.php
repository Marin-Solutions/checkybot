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
}
