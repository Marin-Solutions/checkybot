<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerInformationHistoryRequest;
use App\Http\Requests\UpdateServerInformationHistoryRequest;
use App\Http\Resources\ServerInfoHistoryResource;
use App\Models\Server;
use App\Models\ServerInformationHistory;

class ServerInformationHistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreServerInformationHistoryRequest $request)
    {
        $server = new Server;
        $validated = $request->validated();
        $serverId = $validated['s'] ?? null;
        $token = $request->bearerToken();

        if ($serverId !== null && $serverId !== false) {
            $dataServer = $server->find($serverId);

            if ($dataServer != null) {
                if (! $dataServer->hasReporterToken($token)) {
                    return response()->json(['message' => __('Error: Unauthorized')], 401);
                }

                // Update CPU cores if changed
                if (isset($validated['cpu_cores']) && $dataServer->cpu_cores != $validated['cpu_cores']) {
                    $dataServer->update(['cpu_cores' => $validated['cpu_cores']]);
                }

                // Create history record
                $serverResource = new ServerInfoHistoryResource(ServerInformationHistory::create([
                    'server_id' => $validated['s'],
                    'cpu_load' => $validated['cpu_load'],
                    'ram_free_percentage' => $validated['ram_free_percentage'],
                    'ram_free' => $validated['ram_free'],
                    'disk_free_percentage' => $validated['disk_free_percentage'],
                    'disk_free_bytes' => $validated['disk_free_bytes'],
                ]));

                $dataServer->recordReporterMetadata($request);

                return response()->json($serverResource, 200);
            } else {
                return response()->json(['message' => __('Error: Server id not exists in database')], 406);
            }
        } else {
            return response()->json(['message' => __('The server id is not in this DB')], 406);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ServerInformationHistory $serverInformationHistory)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ServerInformationHistory $serverInformationHistory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateServerInformationHistoryRequest $request, ServerInformationHistory $serverInformationHistory)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServerInformationHistory $serverInformationHistory)
    {
        //
    }
}
