<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServerLogCategory extends Model
{
    use HasFactory;

    //        protected $table = 'server_log_category';

    protected $fillable = [
        'server_id',
        'name',
        'log_directory',
        'should_collect',
        'last_collected_at',
    ];

    protected $casts = [
        'last_collected_at' => 'datetime',
    ];

    public function server(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function files(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServerLogFileHistory::class);
    }

    public function latestFile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ServerLogFileHistory::class)->latestOfMany('created_at');
    }
}
