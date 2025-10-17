<?php

use App\Http\Controllers\WebhookController;
use App\Models\ServerInformationHistory;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect('/admin');
});

// get the script for connect to api server information
Route::get('/reporter/{server_id}/{user}', function ($server_id, $user): Response {
    $response = ServerInformationHistory::doShellScript($server_id, $user);

    return $response;
});

// get the script for connect to api server information
Route::get('/log-reporter/{server_id}/{user}', function ($server_id, $user): Response {
    $response = \App\Models\ServerLogFileHistory::doShellScript($server_id, $user);

    return $response;
});

Route::get('/backup-folder/{backup_id}/{server_id}/{user}/{init}', function ($backup_id, $server_id, $user, $init): Response {
    $response = \App\Models\Backup::doShellScript($backup_id, $server_id, $user, $init);

    return $response;
});

Route::match(['get', 'post'], '/webhook', [WebhookController::class, 'index'])
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::get('welcome', \App\Livewire\Welcome::class);

// SEO Report Downloads
Route::get('/reports/{filename}', function (string $filename) {
    $path = "reports/{$filename}";

    if (! Storage::exists($path)) {
        abort(404, 'Report not found');
    }

    $content = Storage::get($path);
    $mimeType = match (pathinfo($filename, PATHINFO_EXTENSION)) {
        'csv' => 'text/csv',
        'json' => 'application/json',
        'html' => 'text/html',
        default => 'application/octet-stream',
    };

    return response($content)
        ->header('Content-Type', $mimeType)
        ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
})->name('seo.report.download');
