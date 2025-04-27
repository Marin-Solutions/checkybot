<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class Projects extends Model
    {
        protected $table = 'projects';

        protected $fillable = [
            "name",
            "group",
            "environment",
            "technology",
            "token",
            "created_by"
        ];

        public function errorReported(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(ErrorReports::class, 'project_id');
        }
    }
