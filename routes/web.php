<?php

use Illuminate\Support\Facades\Route;
use App\Models\ServerInformationHistory;
use Illuminate\Http\Response;

Route::get('/', function () {
    return redirect('/admin');
});

//get the script for connect to api server information
Route::get('/reporter/{server_id}/{token}', function ($server_id, $token):Response {
    if (!ServerInformationHistory::isValidToken($token)) {
        return response(['error' => 'Invalid Token'], 401);
    }else{
        $response = ServerInformationHistory::doShellScript($server_id,$token);
        return $response;
    }
});
