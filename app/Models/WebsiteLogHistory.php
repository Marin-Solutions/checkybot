<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'ssl_expiry_date' => 'datetime',
            'http_status_code' => 'integer',
            'speed' => 'integer',
            'transport_error_code' => 'integer',
        ];
    }
}
