<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Http\Response;

    class WebhookController extends Controller
    {
        public function index( Request $request, Response $response )
        {
            $input = request()->all();
            return response()->json($input);
        }
    }
