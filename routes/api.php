<?php

use App\Http\Controllers\ServerLogFileHistoryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerInformationHistoryController;

# API Endpoints


Route::group(['prefix'=> 'v1'],function(){
    Route::post('/server-history',[ServerInformationHistoryController::class, 'store']);
    Route::post('/server-log-history',[ ServerLogFileHistoryController::class, 'store']);
});

