<?php

namespace App\Models;

use App\Enums\RunSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
