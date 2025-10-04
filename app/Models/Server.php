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
     * Scope to get servers with their latest information history
     */
    public function scopeWithLatestHistory($query)
    {
        return $query->select('servers.*')
            ->leftJoinSub(
                DB::table('server_information_history')
                    ->select(
                        'server_id',
                        DB::raw("CONCAT('disk_usage:', disk_free_percentage, '|ram_usage:', ram_free_percentage, '|cpu_usage:', cpu_load) as latest_server_history_info"),
                        'created_at as latest_server_history_created_at'
                    )
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('server_information_history as sih2')
                            ->groupBy('sih2.server_id');
                    }),
                'latest_history',
                'servers.id',
                '=',
                'latest_history.server_id'
            )
            ->addSelect('latest_history.latest_server_history_info')
            ->addSelect('latest_history.latest_server_history_created_at');
    }

    /**
     * Get the latest server information history
     */
    public function getLatestHistoryAttribute()
    {
        return $this->informationHistory()->latest()->first();
    }
}
