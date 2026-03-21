<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectComponentHeartbeat extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectComponentHeartbeatFactory> */
    use HasFactory;

    protected $fillable = [
        'project_component_id',
        'component_name',
        'status',
        'event',
        'summary',
        'metrics',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'observed_at' => 'datetime',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProjectComponent::class, 'project_component_id');
    }
}
