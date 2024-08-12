<?php

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebsiteController;

# API Endpoints


Route::group(['prefix'=> 'v1'],function(){
    Route::apiResource('websites',WebsiteController::class);
});
