<?php

use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api', 'api.key'])->group(function () {
    Route::apiResource('servers', ServerController::class);
});
