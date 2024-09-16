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
        $ip =  $request->ip();
        $server  = new Server();
        $serverId = $request->s??false;
        $token = $request->bearerToken();

        if(!$serverId==false){
            $dataServer= $server->find($serverId);

            if( $dataServer != null ){
                if($dataServer->token === $token ){
                    if($dataServer->ip == $ip){
                        $serverResource  = new ServerInfoHistoryResource(ServerInformationHistory::create($request->all()));
                        return response()->json($serverResource, 200);
                    }else{
                        return response()->json(['message' => __('Error: Server IP from request not match with Server IP in DB')], 406);
                    }
                }else{
                    return response()->json(['message' => __('Error: Unauthorized')], 401);
                }
            }else{
                return response()->json(['message' => __('Error: Server id not exists in database')], 406);
            }
        }else{
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
