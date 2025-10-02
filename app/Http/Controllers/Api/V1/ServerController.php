<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServerController extends Controller
{
    public function index()
    {
        $servers = Auth::user()->servers()
            ->with(['informationHistory' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->paginate();

        return ServerResource::collection($servers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'description' => 'required|string|max:255',
        ]);

        $server = Auth::user()->servers()->create($validated);

        return new ServerResource($server);
    }

    public function show(Server $server)
    {
        $this->authorize('view', $server);

        return new ServerResource($server);
    }

    public function update(Request $request, Server $server)
    {
        $this->authorize('update', $server);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'ip' => 'sometimes|ip',
            'description' => 'sometimes|string|max:255',
        ]);

        $server->update($validated);

        return new ServerResource($server);
    }

    public function destroy(Server $server)
    {
        $this->authorize('delete', $server);
        $server->delete();

        return response()->noContent();
    }
}
