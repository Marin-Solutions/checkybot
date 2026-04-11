<?php

use App\Http\Controllers\WebhookController;
use App\Models\Backup;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogFileHistory;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect('/admin');
});

// get the script for connect to api server information
Route::get('/reporter/{server_id}/{user}', function (int $server_id, int $user): Response {
    $response = ServerInformationHistory::doShellScript($server_id, $user);

    return $response;
})->name('server-info.script.download')
    ->middleware('signed');

// get the script for connect to api server information
Route::get('/log-reporter/{server_id}/{user}', function (int $server_id, int $user): Response {
    $response = ServerLogFileHistory::doShellScript($server_id, $user);

    return $response;
})->name('server-log.script.download')
    ->middleware('signed');

Route::get('/backup-folder/{backup_id}/{server_id}/{user}/{init}', function (int $backup_id, int $server_id, int $user, int $init): Response {
    $response = Backup::doShellScript($backup_id, $server_id, $user, $init);

    return $response;
})->name('backup.script.download')
    ->middleware('signed');

Route::post('/webhook', [WebhookController::class, 'index'])
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::get('welcome', \App\Livewire\Welcome::class);

// SEO Report Downloads - requires authentication
Route::get('/reports/{filename}', function (string $filename) {
    // Validate filename to prevent path traversal attacks
    if (
        str_contains($filename, '..') ||
        str_contains($filename, '/') ||
        str_contains($filename, '\\') ||
        preg_match('/[\x00-\x1f\x7f]/', $filename)
    ) {
        abort(403, 'Access denied');
    }

    $path = 'reports/'.auth()->id()."/{$filename}";

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
})->name('seo.report.download')
    ->middleware(['auth']);
