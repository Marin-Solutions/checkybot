<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'group',
        'environment',
        'technology',
        'token',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function monitorApis(): HasMany
    {
        return $this->hasMany(MonitorApis::class);
    }

    public function packageManagedWebsites(): HasMany
    {
        return $this->hasMany(Website::class)->where('source', 'package');
    }

    public function packageManagedApis(): HasMany
    {
        return $this->hasMany(MonitorApis::class)->where('source', 'package');
    }
}
