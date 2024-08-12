<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'description',
        'created_by',
        'uptime_check',
        'uptime_interval',
    ];

    /**
     * Undocumented function
     *
     * @param [string] $url
     * @return boolean
     */
    public function checkWebsiteExists(?string $url ): ?bool
    {
        return true ;
    }

    /**
     * check if it is a valid url
     *
     * @return boolean
     */
    public function checkValidURL(): ?bool
    {
        return true ;
    }

    /**
     * check if response is 200
     *
     * @return boolean
     */
    public function checkResponse(): ?bool
    {
        return true ;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
