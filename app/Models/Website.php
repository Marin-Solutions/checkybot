<?php

namespace App\Models;

use App\Models\Concerns\HasSnooze;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Dns\Dns;
use Spatie\SslCertificate\SslCertificate;

/**
 * @property int|null $uptime_interval
 */
class Website extends Model
{
    use HasFactory;
    use HasSnooze;
    use SoftDeletes;

    protected $fillable = [
        'ploi_website_id',
        'name',
        'url',
        'description',
        'created_by',
        'project_id',
        'uptime_check',
        'uptime_interval',
        'ssl_check',
        'ssl_expiry_date',
        'outbound_check',
        'last_outbound_checked_at',
        'source',
        'package_name',
        'package_interval',
        'last_synced_at',
        'current_status',
        'last_heartbeat_at',
        'stale_at',
        'status_summary',
        'silenced_until',
    ];

    protected $casts = [
        'uptime_check' => 'boolean',
        'uptime_interval' => 'integer',
        'ssl_check' => 'boolean',
        'outbound_check' => 'boolean',
        'last_outbound_checked_at' => 'datetime',
        'ssl_expiry_reminder_sent_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'stale_at' => 'datetime',
        'silenced_until' => 'datetime',
    ];

    /**
     * Check website exists with look up dns spatie library
     *
     * @param [string] $url to check
     */
    public static function checkWebsiteExists(?string $url): ?bool
    {
        $host = static::extractHost($url);

        if (blank($host)) {
            return false;
        }

        try {
            $dns = app(Dns::class);
            $records = $dns->getRecords($host, 'A');

            return count($records) > 0;
        } catch (\Exception $e) {
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
            $response = Http::timeout(10)->get($url);
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
        $dataResponse['code'] = $response->status();
        $dataResponse['body'] = $response->reason() ?: 'Unexpected response received.';

        return $dataResponse;
    }

    /**
     * Check website ssl expiry code
     *
     * @param [string] $url to check
     */
    public static function sslExpiryDate(?string $url): ?string
    {
        $host = static::extractHost($url);

        if (blank($host)) {
            return null;
        }

        try {
            $certificate = SslCertificate::createForHostName($host);
            $expiration_date = $certificate->expirationDate();

            return $expiration_date;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function extractHost(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false && self::isOpaqueUri($url)) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) !== 1) {
            $schemedHost = parse_url('https://'.$url, PHP_URL_HOST);
            $normalizedSchemedHost = self::normalizeParsedHost($schemedHost);

            if (is_string($normalizedSchemedHost) && self::isValidHost($normalizedSchemedHost)) {
                return $normalizedSchemedHost;
            }
        }

        return self::isValidHost($url)
            ? $url
            : null;
    }

    public static function extractPort(?string $url, int $default = 443): int
    {
        if (blank($url)) {
            return $default;
        }

        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port)) {
            return $port;
        }

        $url = trim($url);

        if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) === 1) {
            return $default;
        }

        if (filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false || self::isOpaqueUri($url)) {
            return $default;
        }

        $schemedPort = parse_url('https://'.$url, PHP_URL_PORT);

        return is_int($schemedPort) ? $schemedPort : $default;
    }

    protected static function isOpaqueUri(string $value): bool
    {
        if (preg_match('/^(?<scheme>[a-z][a-z0-9+.-]*):(?<rest>.*)$/i', $value, $matches) !== 1) {
            return false;
        }

        if (str_starts_with($matches['rest'], '//')) {
            return false;
        }

        return preg_match('/^\d+(?:[\/?#]|$)/', $matches['rest']) !== 1;
    }

    protected static function normalizeParsedHost(string|false|null $host): ?string
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (
            str_starts_with($host, '[')
            && str_ends_with($host, ']')
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    protected static function isValidHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || strtolower($host) === 'localhost'
            || preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/i', $host) === 1;
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function notificationChannels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationSetting::class)->websiteScope()->active();
    }

    public function notificationSettings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationSetting::class)->websiteScope();
    }

    public function getBaseURL(): string
    {
        $parsedUrl = parse_url($this->url);
        $baseUrl = $parsedUrl['scheme'].'://'.$parsedUrl['host'];

        if (isset($parsedUrl['port'])) {
            $baseUrl .= ':'.$parsedUrl['port'];
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

    public function logHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WebsiteLogHistory::class);
    }

    public function outboundLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OutboundLink::class);
    }

    public function latestLogHistory(): HasOne
    {
        return $this->hasOne(WebsiteLogHistory::class)->latestOfMany();
    }

    public function latestScheduledLogHistory(): HasOne
    {
        return $this->hasOne(WebsiteLogHistory::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('is_on_demand', false),
        );
    }

    public function latestDiagnosticLogHistory(): HasOne
    {
        return $this->hasOne(WebsiteLogHistory::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('is_on_demand', true),
        );
    }

    public function logHistoryLast24h(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WebsiteLogHistory::class)
            ->where('is_on_demand', false)
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

    public function seoSchedule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SeoSchedule::class);
    }

    public function getLatestSeoCheckStatusAttribute(): ?string
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->status;
        }

        return $this->latestSeoCheck?->status;
    }

    public function getLatestSeoCheckUrlsCrawledAttribute(): int
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->total_urls_crawled ?? 0;
        }

        return $this->latestSeoCheck?->total_urls_crawled ?? 0;
    }

    public function getLatestSeoCheckErrorsCountAttribute(): int
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->errors_count ?? 0;
        }

        return $this->latestSeoCheck?->errors_count ?? 0;
    }

    public function getLatestSeoCheckWarningsCountAttribute(): int
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->warnings_count ?? 0;
        }

        return $this->latestSeoCheck?->warnings_count ?? 0;
    }

    public function getLatestSeoCheckNoticesCountAttribute(): int
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->notices_count ?? 0;
        }

        return $this->latestSeoCheck?->notices_count ?? 0;
    }

    public function getLatestSeoCheckFinishedAtAttribute(): ?\Illuminate\Support\Carbon
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->finished_at;
        }

        return $this->latestSeoCheck?->finished_at;
    }

    public function getLatestSeoCheckHealthScoreAttribute(): ?float
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->health_score;
        }

        return $this->latestSeoCheck?->health_score;
    }

    public function getLatestSeoCheckHealthScoreFormattedAttribute(): ?string
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->health_score_formatted;
        }

        return $this->latestSeoCheck?->health_score_formatted;
    }

    public function getLatestSeoCheckHealthScoreColorAttribute(): ?string
    {
        // Use loaded relationship if available to avoid N+1 queries
        if ($this->relationLoaded('latestSeoCheck')) {
            return $this->latestSeoCheck?->health_score_color;
        }

        return $this->latestSeoCheck?->health_score_color;
    }
}
