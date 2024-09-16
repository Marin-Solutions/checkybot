<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip',
        'hostname',
        'description',
        'created_by',
        'token',

    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
