<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class ErrorReportingSystem extends Model
    {
        use HasFactory;

        protected $connection = 'mysql';
        protected $table = 'error_reporting_systems';
        protected $fillable = [
            'body',
            'headers'
        ];
    }
