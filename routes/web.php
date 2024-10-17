<?php

    use App\Http\Controllers\WebhookController;
    use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
    use Illuminate\Support\Facades\Route;
    use App\Models\ServerInformationHistory;
    use Illuminate\Http\Response;

    Route::get('/', function () {
        return redirect('/admin');
    });

//get the script for connect to api server information
    Route::get('/reporter/{server_id}/{user}', function ( $server_id, $user ): Response {
        $response = ServerInformationHistory::doShellScript($server_id, $user);
        return $response;
    });

//get the script for connect to api server information
    Route::get('/log-reporter/{server_id}/{user}', function ( $server_id, $user ): Response {
        $response = \App\Models\ServerLogFileHistory::doShellScript($server_id, $user);
        return $response;
    });

    Route::match([ 'get', 'post' ], '/webhook', [ WebhookController::class, 'index' ])
        ->withoutMiddleware([ VerifyCsrfToken::class ])
    ;
