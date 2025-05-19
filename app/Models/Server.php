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

    function parseLatestServerHistoryInfo(?string $summary = null): array {
        $result = [];
        if ( !empty($summary) ) {
            foreach (explode('|', $summary) as $part) {
                [$key, $value] = explode(':', $part);
                $result[$key] = $value;
            }
        }
        return $result;
    }

}
