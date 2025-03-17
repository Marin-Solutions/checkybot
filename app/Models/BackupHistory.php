<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

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
            'message'
        ];
    }
