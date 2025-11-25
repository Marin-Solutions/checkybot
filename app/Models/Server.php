<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip',
        'name',
        'description',
        'cpu_cores',
        'created_by',
        'token',
        'ploi_server_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logCategories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServerLogCategory::class);
    }

    public function informationHistory()
    {
        return $this->hasMany(ServerInformationHistory::class);
    }

    public function rules()
    {
        return $this->hasMany(ServerRule::class);
    }

    public function parseLatestServerHistoryInfo(?string $summary = null): array
    {
        $result = [];
        if (! empty($summary)) {
            foreach (explode('|', $summary) as $part) {
                [$key, $value] = explode(':', $part);
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function ploiServer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PloiServers::class, 'ploi_server_id', 'id');
    }

    /**
     * Scope to get servers with their latest information history
     * Uses correlated subqueries for MySQL/MariaDB compatibility
     */
    public function scopeWithLatestHistory($query)
    {
        return $query->select('servers.*')
            ->addSelect([
                'latest_server_history_info' => ServerInformationHistory::selectRaw(
                    "CONCAT('disk_usage:', disk_free_percentage, '|ram_usage:', ram_free_percentage, '|cpu_usage:', cpu_load)"
                )
                    ->whereColumn('server_id', 'servers.id')
                    ->orderBy('id', 'desc')
                    ->limit(1),
                'latest_server_history_created_at' => ServerInformationHistory::select('created_at')
                    ->whereColumn('server_id', 'servers.id')
                    ->orderBy('id', 'desc')
                    ->limit(1),
            ]);
    }

    /**
     * Get the latest server information history
     */
    public function getLatestHistoryAttribute()
    {
        return $this->informationHistory()->latest()->first();
    }

    /**
     * Ensure latest_server_history_info is always accessible, even when NULL
     */
    public function getLatestServerHistoryInfoAttribute($value): ?string
    {
        return $value;
    }

    /**
     * Ensure latest_server_history_created_at is always accessible, even when NULL
     */
    public function getLatestServerHistoryCreatedAtAttribute($value): ?string
    {
        return $value;
    }
}
