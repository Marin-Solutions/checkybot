<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerLogHistoryRequest;
use App\Models\ServerLogCategory;
use App\Models\ServerLogFileHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ServerLogFileHistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreServerLogHistoryRequest $request)
    {
        $id = $request->input('li');
        $serverLogCategory = ServerLogCategory::query()->where('id', $id)->first();

        if (! $serverLogCategory) {
            return response()->json(['message' => __('The server log category not found')], 404);
        }

        $server = $serverLogCategory->server;
        $token = $request->bearerToken();

        if (! $server) {
            return response()->json(['message' => __('The server id is not in this DB')], 404);
        }

        if (! $server->hasReporterToken($token)) {
            return response()->json(['message' => __('Error: Unauthorized')], 401);
        }

        $file = Storage::putFile('ServerLogFiles', $request->file('log'));
        $newServerLogFileHistory = [
            'server_log_category_id' => request()->input('li'),
            'log_file_name' => $file,
        ];
        ServerLogFileHistory::create($newServerLogFileHistory);
        $serverLogCategory->forceFill([
            'last_collected_at' => now(),
        ])->save();

        $server->recordReporterMetadata($request);

        return response()->json($newServerLogFileHistory, 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(ServerLogFileHistory $serverLogFileHistory)
    {
        //
    }

    public function download(ServerLogFileHistory $serverLogFileHistory)
    {
        $server = $serverLogFileHistory->logCategory?->server;

        if (! $server) {
            abort(404);
        }

        Gate::authorize('view', $server);

        if (! Storage::exists($serverLogFileHistory->log_file_name)) {
            abort(404, 'Log file not found');
        }

        return Storage::download(
            $serverLogFileHistory->log_file_name,
            basename($serverLogFileHistory->log_file_name),
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ServerLogFileHistory $serverLogFileHistory)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServerLogFileHistory $serverLogFileHistory)
    {
        //
    }
}
