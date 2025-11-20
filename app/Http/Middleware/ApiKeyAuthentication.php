<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

class ApiKeyAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-Key');

        if (! $apiKey) {
            return response()->json(['message' => 'API key is missing'], 401);
        }

        $key = ApiKey::where('key', $apiKey)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $key) {
            return response()->json(['message' => 'Invalid or expired API key'], 401);
        }

        $key->update(['last_used_at' => now()]);
        auth()->login($key->user);

        return $next($request);
    }
}
