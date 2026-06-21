<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyPoolIntegration extends Model
{
    /** @use HasFactory<\Database\Factories\ProxyPoolIntegrationFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'project_component_id',
        'created_by',
        'name',
        'base_url',
        'token',
        'check_interval',
        'is_active',
        'last_sync_status',
        'last_sync_error',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProjectComponent::class, 'project_component_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
