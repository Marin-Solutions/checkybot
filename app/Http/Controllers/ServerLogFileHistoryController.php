<?php

    namespace App\Http\Controllers;

    use App\Http\Requests\StoreServerLogHistoryRequest;
    use App\Http\Resources\ServerInfoHistoryResource;
    use App\Models\Server;
    use App\Models\ServerInformationHistory;
    use App\Models\ServerLogCategory;
    use App\Models\ServerLogFileHistory;
    use Illuminate\Http\Request;
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
        public function store( StoreServerLogHistoryRequest $request )
        {
            $ip                = $request->ip();
            $id                = $request->input('li');
            $serverLogCategory = ServerLogCategory::query()->where('id', $id)->first();
            $server            = $serverLogCategory->server ?? false;
            $token             = $request->bearerToken();

            if ( !$server ) {
                return response()->json([ 'message' => __('The server id is not in this DB') ], 406);
            } else {
                if ( $server->token === $token ) {
                    if ( $server->ip == $ip ) {
                        $file                    = Storage::putFile('ServerLogFiles', $request->file('log'));
                        $newServerLogFileHistory = [
                            'server_log_category_id' => request()->input('li'),
                            'log_file_name'          => $file
                        ];
                        ServerLogFileHistory::create($newServerLogFileHistory);
                        return response()->json($newServerLogFileHistory, 200);
                    } else {
                        return response()->json([ 'message' => __('Error: Server IP from request not match with Server IP in DB') ], 406);
                    }
                } else {
                    return response()->json([ 'message' => __('Error: Unauthorized') ], 401);
                }
            }
        }

        /**
         * Display the specified resource.
         */
        public function show( ServerLogFileHistory $serverLogFileHistory )
        {
            //
        }

        /**
         * Update the specified resource in storage.
         */
        public function update( Request $request, ServerLogFileHistory $serverLogFileHistory )
        {
            //
        }

        /**
         * Remove the specified resource from storage.
         */
        public function destroy( ServerLogFileHistory $serverLogFileHistory )
        {
            //
        }
    }
