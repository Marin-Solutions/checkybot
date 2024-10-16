<?php

namespace App\Models;

use App\Enums\NotificationScopesEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Dns\Dns;
use Ramsey\Uuid\Type\Integer;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Spatie\SslCertificate\SslCertificate;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as Collection2;
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
        'ssl_check',
        'ssl_expiry_date',
        'outbound_check',
        'last_outbound_checked_at'
    ];

    protected $casts = [
        'last_outbound_checked_at' => 'datetime'
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


    /**
     * Check website ssl expiry code
     *
     * @param [string] $url to check
     * @return array
     */

    public static function sslExpiryDate(?string $url ): string
     {
        $certificate = SslCertificate::createForHostName($url);
        $expiration_date= $certificate->expirationDate();

        return $expiration_date;
    }


    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notificationChannels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationSetting::class)->websiteScope()->active();
    }
  
    public function getBaseURL(): string
    {
        $parsedUrl = parse_url($this->url);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        if (isset($parsedUrl['port'])) {
            $baseUrl .= ':' . $parsedUrl['port'];
        }

        return $baseUrl;
    }
}
