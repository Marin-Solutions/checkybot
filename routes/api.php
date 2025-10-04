<?php

use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\BackupHistoryController;
use App\Http\Controllers\ServerInformationHistoryController;
use App\Http\Controllers\ServerLogFileHistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::apiResource('servers', ServerController::class);

    Route::post('/server-history', [ServerInformationHistoryController::class, 'store']);
    Route::post('/server-log-history', [ServerLogFileHistoryController::class, 'store']);
    Route::post('/backup-history', [BackupHistoryController::class, 'store']);
});
