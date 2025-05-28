<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class ErrorReports extends Model
    {
        use HasFactory;

        protected $connection = 'mysql';
        protected $table = 'error_reports';

        protected $guarded = [];

        protected $casts = [
            'seen_at'             => 'datetime',
            'glows'               => 'array',
            'solutions'           => 'array',
            'documentation_links' => 'array',
            'stacktrace'          => 'array',
            'context'             => 'array',
            'handled'             => 'boolean',
            'is_resolved'         => 'boolean',
        ];

        public function projects(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(Projects::class, 'project_id');
        }

        public function publicLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(ErrorReportPublicLink::class, 'error_report_id');
        }

        public function getRequestMethodAttribute()
        {
            return $this->context['request']['method'] ?? null;
        }

        public function getRequestHeadersAttribute()
        {
            return $this->context['headers'] ?? [];
        }

        public function getRequestBodyAttribute()
        {
            return $this->context['request_data']['body'] ?? [];
        }

        public function getRouteNameAttribute()
        {
            return $this->context['route']['route'] ?? null;
        }

        public function getRouteActionAttribute()
        {
            return $this->context['route']['controllerAction'] ?? null;
        }

        public function getRouteMiddlewareAttribute()
        {
            return $this->context['route']['middleware'] ?? [];
        }

        public function getRouteParametersAttribute()
        {
            return $this->context['route']['routeParameters'] ?? [];
        }

        public function getQueriesAttribute()
        {
            return $this->context['queries'] ?? [];
        }
    }
