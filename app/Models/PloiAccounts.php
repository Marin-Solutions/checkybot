<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PloiAccounts extends Model
{
    protected $fillable = [
        'label',
        'created_by',
        'key',
        'is_verified',
        'error_message',
    ];

    protected $casts = [
        'is_verified' => 'bool',
    ];

    public function servers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PloiServers::class, 'ploi_account_id');
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PloiWebsites::class, 'ploi_account_id');
    }
}
