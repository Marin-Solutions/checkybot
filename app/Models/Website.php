<?php

namespace App\Models;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Dns\Dns;
use Spatie\SslCertificate\SslCertificate;

class Website extends Model
{
    use HasFactory;

    protected $fillable = [
        'ploi_website_id',
        'name',
        'url',
        'description',
        'created_by',
        'uptime_check',
        'uptime_interval',
        'ssl_check',
        'ssl_expiry_date',
        'outbound_check',
        'last_outbound_checked_at',
    ];

    protected $casts = [
        'last_outbound_checked_at' => 'datetime',
    ];

    /**
     * Check website exists with look up dns spatie library
     *
     * @param [string] $url to check
     */
    public static function checkWebsiteExists(?string $url): ?bool
    {
        $dns = new Dns;
        $records = $dns->getRecords($url, 'A');

        if (count($records) > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check website response code
     *
     * @param [string] $url to check
     */
    public static function checkResponseCode(?string $url): array
    {
        $dataResponse = [];
        try {
            $response = Http::get($url);
        } catch (RequestException $e) {
            $handlerContext = $e->getHandlerContext();
            $dataResponse['code'] = $handlerContext['errno'];
            $dataResponse['body'] = $handlerContext['error'];

            return $dataResponse;
        } catch (ConnectionException $e) {
            $dataResponse['code'] = 0;
            $dataResponse['body'] = $e->getMessage();

            return $dataResponse;
        }
        $dataResponse['code'] = $response->ok() ? 200 : 0;
        $dataResponse['body'] = 1;

        return $dataResponse;
    }

    /**
     * Check website ssl expiry code
     *
     * @param [string] $url to check
     * @return array
     */
    public static function sslExpiryDate(?string $url): string
    {
        $certificate = SslCertificate::createForHostName($url);
        $expiration_date = $certificate->expirationDate();

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

    public function globalNotifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'user_id', 'created_by')->where('inspection', 'WEBSITE_CHECK')->globalScope()->active();
    }

    public function individualNotifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'website_id')->websiteScope()->active();
    }

    public function logHistory()
    {
        return $this->hasMany(WebsiteLogHistory::class);
    }

    public function logHistoryLast24h(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WebsiteLogHistory::class)
            ->where('created_at', '>=', now()->subHours(24));
    }

    public function PlowWebsite(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PloiWebsites::class, 'ploi_website_id', 'id');
    }

    public function seoChecks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SeoCheck::class);
    }

    public function latestSeoCheck(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SeoCheck::class)->latest();
    }
}
