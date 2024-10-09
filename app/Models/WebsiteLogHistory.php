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
        'speed'
    ];
}
