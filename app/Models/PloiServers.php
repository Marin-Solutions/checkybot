<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class PloiServers extends Model
    {
        protected $fillable = [
            'ploi_account_id',
            'server_id',
            'type',
            'name',
            'ip_address',
            'php_version',
            'mysql_version',
            'sites_count',
            'status',
            'status_id',
            'created_by'
        ];

        protected $casts = [];

        public function PloiAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(PloiAccounts::class, 'ploi_account_id');
        }
    }
