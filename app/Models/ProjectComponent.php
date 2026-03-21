<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectComponent extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectComponentFactory> */
    use HasFactory;

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
        'archived_at',
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
            'archived_at' => 'datetime',
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
