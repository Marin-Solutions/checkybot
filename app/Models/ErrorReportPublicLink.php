<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class ErrorReportPublicLink extends Model
    {
        protected $table = 'error_report_public_links';

        protected $fillable = [
            'error_report_id',
            'created_by',
            'token'
        ];

        public function errorReport(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(ErrorReports::class, 'error_report_id');
        }
    }
