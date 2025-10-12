<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupIntervalOption extends Model
{
    use HasFactory;

    protected $table = 'backup_interval_options';

    protected $fillable = [
        'value',
        'unit',
        'label',
        'expression',
    ];
}
