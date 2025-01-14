<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\ServerInformationHistoryController;
use App\Http\Controllers\ServerLogFileHistoryController;

Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::apiResource('servers', ServerController::class);

    Route::post('/server-history', [ServerInformationHistoryController::class, 'store']);
    Route::post('/server-log-history', [ServerLogFileHistoryController::class, 'store']);
});
