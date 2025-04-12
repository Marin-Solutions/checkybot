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
    }
