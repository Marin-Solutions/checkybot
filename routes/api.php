<?php

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\ServerInformationHistoryController;

# API Endpoints


Route::group(['prefix'=> 'v1'],function(){
    Route::post('/server-history',[ServerInformationHistoryController::class, 'store']);
//     Route::patch('/websites/{website}/uptime-check',[WebsiteController::class, 'updateUptimeCheck']);
});

