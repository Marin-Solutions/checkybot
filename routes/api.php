<?php

use App\Http\Controllers\Api\V1\PackageSyncController;
use App\Http\Controllers\Api\V1\ProjectChecksController;
use App\Http\Controllers\Api\V1\ProjectComponentsController;
use App\Http\Controllers\Api\V1\ProjectRegistrationsController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\BackupHistoryController;
use App\Http\Controllers\ServerInformationHistoryController;
use App\Http\Controllers\ServerLogFileHistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::apiResource('servers', ServerController::class)->middleware('auth');

    Route::post('/server-history', [ServerInformationHistoryController::class, 'store']);
    Route::post('/server-log-history', [ServerLogFileHistoryController::class, 'store']);
    Route::post('/backup-history', [BackupHistoryController::class, 'store']);

    Route::post('/package/register', ProjectRegistrationsController::class)->middleware('api.key');
    Route::post('/package/sync', PackageSyncController::class)->middleware('api.key');
    Route::post('/projects/{project}/checks/sync', [ProjectChecksController::class, 'sync'])->middleware('api.key');
    Route::post('/projects/{project}/components/sync', ProjectComponentsController::class)->middleware('api.key');
});
