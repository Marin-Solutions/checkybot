<?php

use App\Http\Controllers\Api\V1\CheckybotControlController;
use App\Http\Controllers\Api\V1\CheckybotMcpController;
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
    Route::middleware('api.key')->group(function () {
        Route::get('/projects/{project}', [ProjectChecksController::class, 'project']);
        Route::get('/projects/{project}/checks', [ProjectChecksController::class, 'index']);
        Route::get('/projects/{project}/checks/{check}', [ProjectChecksController::class, 'show'])->where('check', '[A-Za-z0-9:_-]+');
        Route::get('/projects/{project}/checks/{check}/results', [ProjectChecksController::class, 'results'])->where('check', '[A-Za-z0-9:_-]+');
    });
    Route::post('/projects/{project}/checks/sync', [ProjectChecksController::class, 'sync'])->middleware('api.key');
    Route::post('/projects/{project}/components/sync', ProjectComponentsController::class)->middleware('api.key');

    Route::middleware('api.key')->prefix('control')->group(function () {
        Route::get('/me', [CheckybotControlController::class, 'me']);
        Route::get('/projects', [CheckybotControlController::class, 'projects']);
        Route::get('/projects/{project}', [CheckybotControlController::class, 'project']);
        Route::get('/projects/{project}/checks', [CheckybotControlController::class, 'checks']);
        Route::put('/projects/{project}/checks/{check}', [CheckybotControlController::class, 'upsertCheck'])->where('check', '[A-Za-z0-9_-]+');
        Route::patch('/projects/{project}/checks/{check}/disable', [CheckybotControlController::class, 'disableCheck'])->where('check', '[A-Za-z0-9_-]+');
        Route::post('/projects/{project}/runs', [CheckybotControlController::class, 'triggerProjectRun']);
        Route::post('/projects/{project}/checks/{check}/runs', [CheckybotControlController::class, 'triggerCheckRun'])->where('check', '[A-Za-z0-9_-]+');
        Route::get('/runs', [CheckybotControlController::class, 'runs']);
        Route::get('/projects/{project}/runs', [CheckybotControlController::class, 'projectRuns']);
        Route::get('/failures', [CheckybotControlController::class, 'failures']);
        Route::get('/projects/{project}/failures', [CheckybotControlController::class, 'projectFailures']);
    });

    Route::post('/mcp', CheckybotMcpController::class)->middleware('api.key');
});
