<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundLink extends Model
{
    use HasFactory;

    protected $table = 'outbound_link';

    protected $fillable = [
        'website_id',
        'found_on',
        'outgoing_url',
        'http_status_code',
        'last_checked_at',
    ];
}
