<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->bearerToken();

        if (! $apiKey) {
            return response()->json(['message' => 'Bearer API key is missing'], 401);
        }

        $key = ApiKey::query()
            ->where('key', $apiKey)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $key) {
            return response()->json(['message' => 'Invalid or expired API key'], 401);
        }

        $key->update(['last_used_at' => now()]);

        auth()->setUser($key->user);
        $request->setUserResolver(static fn () => $key->user);

        return $next($request);
    }
}
