<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function index(Request $request): Response
    {
        // Validate webhook secret token
        $secret = $request->header('X-Webhook-Secret');

        if (! $secret || $secret !== config('app.webhook_secret')) {
            return response()->json(['error' => 'Invalid webhook secret'], 401);
        }

        return response()->json([
            'status' => 'received',
            'message' => 'Webhook received',
        ]);
    }
}
