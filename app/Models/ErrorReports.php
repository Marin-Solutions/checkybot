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
            'seen_at' => 'datetime',
            'glows' => 'array',
            'solutions' => 'array',
            'documentation_links' => 'array',
            'stacktrace' => 'array',
            'context' => 'array',
            'handled' => 'boolean',
        ];

        public function projects(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(Projects::class, 'project_id');
        }

        public function publicLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(ErrorReportPublicLink::class, 'error_report_id');
        }
    }
