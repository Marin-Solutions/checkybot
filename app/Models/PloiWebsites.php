<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class PloiWebsites extends Model
    {
        protected $fillable = [
            'ploi_account_id',
            'created_by',
            'site_id',
            'status',
            'server_id',
            'domain',
            'deploy_script',
            'web_directory',
            'project_type',
            'project_root',
            'last_deploy_at',
            'system_user',
            'php_version',
            'health_url',
            'notification_urls',
            'has_repository',
            'site_created_at',
        ];

        public function server(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(PloiServers::class, 'server_id', 'server_id');
        }
    }
