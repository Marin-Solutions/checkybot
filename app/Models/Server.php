<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * Scope to get servers with their latest information history.
     * Uses JOIN with derived table for optimal performance on large datasets.
     */
    public function scopeWithLatestHistory($query)
    {
        $latestIds = ServerInformationHistory::query()
            ->selectRaw('server_id, MAX(id) as max_id')
            ->groupBy('server_id');

        return $query
            ->select('servers.*')
            ->leftJoinSub($latestIds, 'latest_ids', function ($join) {
                $join->on('servers.id', '=', 'latest_ids.server_id');
            })
            ->leftJoin('server_information_history as sih', 'sih.id', '=', 'latest_ids.max_id')
            ->addSelect([
                DB::raw("CONCAT('disk_usage:', COALESCE(sih.disk_free_percentage, ''), '|ram_usage:', COALESCE(sih.ram_free_percentage, ''), '|cpu_usage:', COALESCE(sih.cpu_load, '')) as latest_server_history_info"),
                DB::raw('sih.created_at as latest_server_history_created_at'),
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
