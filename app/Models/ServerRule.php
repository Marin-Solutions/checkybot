<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'metric',      // cpu_usage, ram_usage, disk_usage
        'operator',    // >, <, =
        'value',      // percentage value
        'channel',    // notification channel (email, slack, etc)
        'is_active',
    ];

    protected $casts = [
        'value' => 'float',
        'is_active' => 'boolean',
        'is_triggered' => 'boolean',
        'triggered_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ServerRule $rule) {
            if ($rule->isDirty('is_active') && ! $rule->is_active) {
                $rule->is_triggered = false;
                $rule->triggered_at = null;
                $rule->recovered_at = null;
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
