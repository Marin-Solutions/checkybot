<?php

namespace App\Models;

use App\Enums\RunSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteLogHistory extends Model
{
    use HasFactory;

    protected $table = 'website_log_history';

    protected $fillable = [
        'website_id',
        'ssl_expiry_date',
        'http_status_code',
        'speed',
        'status',
        'summary',
        'transport_error_type',
        'transport_error_message',
        'transport_error_code',
        'run_source',
        'is_on_demand',
    ];

    protected function casts(): array
    {
        return [
            'ssl_expiry_date' => 'datetime',
            'http_status_code' => 'integer',
            'speed' => 'integer',
            'transport_error_code' => 'integer',
            'run_source' => RunSource::class,
            'is_on_demand' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (WebsiteLogHistory $history): void {
            if ($history->is_on_demand === true || $history->created_at === null) {
                return;
            }

            Website::query()
                ->whereKey($history->website_id)
                ->where(function ($query) use ($history): void {
                    $query
                        ->whereNull('latest_scheduled_result_at')
                        ->orWhere('latest_scheduled_result_at', '<', $history->created_at);
                })
                ->toBase()
                ->update(['latest_scheduled_result_at' => $history->created_at]);
        });
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
