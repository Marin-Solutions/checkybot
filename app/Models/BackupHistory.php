<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupHistory extends Model
{
    use HasFactory;

    protected $table = 'backup_histories';

    protected $fillable = [
        'backup_id',
        'filename',
        'filesize',
        'is_zipped',
        'is_uploaded',
        'message',
    ];

    protected $casts = [
        'is_zipped' => 'boolean',
        'is_uploaded' => 'boolean',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
