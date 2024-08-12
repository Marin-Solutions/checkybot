<?php

namespace App\Models;

use Spatie\Dns\Dns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        $dns = new Dns();
        $records = $dns->getRecords($url,'A');



        if(count($records)>0){
            return true;
        }else{
            return false;
        }

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
