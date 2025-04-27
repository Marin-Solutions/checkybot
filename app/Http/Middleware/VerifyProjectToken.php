<?php

namespace App\Http\Middleware;

use App\Models\Projects;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyProjectToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingToken = $request->header('x-api-token');

        if (!$incomingToken) {
            return response()->json(['errors' => 'Missing token'], Response::HTTP_UNAUTHORIZED);
        }

        $project = Projects::query()->where('token', $incomingToken)->first();

        if (!$project) {
            return response()->json(['errors' => 'Invalid token'], Response::HTTP_UNAUTHORIZED);
        }

        $request->merge(['project' => $project]);

        return $next($request);
    }
}
