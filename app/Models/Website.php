<?php

namespace App\Models;

use Spatie\Dns\Dns;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Ramsey\Uuid\Type\Integer;

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
     * Check website exists with look up dns spatie library
     *
     * @param [string] $url to check
     * @return boolean
     */

    public static function checkWebsiteExists(?string $url ): ?bool
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
     * Check website response code
     *
     * @param [string] $url to check
     * @return array
     */

    public static function checkResponseCode(?string $url ): array
    {
        $dataResponse = array();
        try {
            $response = Http::get($url);
        } catch (RequestException $e) {
            $handlerContext = $e->getHandlerContext();
            $dataResponse['code'] = $handlerContext['errno'];
            $dataResponse['body'] = $handlerContext['error'];
            return $dataResponse;
        }
        $dataResponse['code'] = $response->ok()?200:0;
        $dataResponse['body'] = 1;
        return $dataResponse;
    }


    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
