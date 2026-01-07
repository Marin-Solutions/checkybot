<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function index(Request $request): Response
    {
        // Validate webhook secret token with timing-safe comparison
        $secret = $request->header('X-Webhook-Secret');
        $expectedSecret = config('app.webhook_secret');

        if (! $secret || ! $expectedSecret || ! hash_equals($expectedSecret, $secret)) {
            return response()->json(['error' => 'Invalid webhook secret'], 401);
        }

        return response()->json([
            'status' => 'received',
            'message' => 'Webhook received',
        ]);
    }
}
