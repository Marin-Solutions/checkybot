<?php

    namespace App\Http\Controllers;

    use App\Models\ErrorReportingSystem;
    use App\Models\ErrorReports;
    use Illuminate\Http\Request;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Log;

    class ErrorReportingSystemController extends Controller
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
        public function store( Request $request )
        {
            $payload = json_decode($request->getContent(), true); // ambil body JSON

            if ( !is_array($payload) ) {
                return response()->json([ 'error' => 'Invalid JSON' ], 400);
            }

            try {
                ErrorReports::create([
                    'project_id'          => $request->get('project')->id,
                    'notifier'            => $payload[ 'notifier' ] ?? null,
                    'language'            => $payload[ 'language' ] ?? null,
                    'framework_version'   => $payload[ 'framework_version' ] ?? null,
                    'language_version'    => $payload[ 'language_version' ] ?? null,
                    'exception_class'     => $payload[ 'exception_class' ] ?? null,
                    'seen_at'             => isset($payload[ 'seen_at' ]) ? Carbon::createFromTimestamp($payload[ 'seen_at' ]) : null,
                    'message'             => $payload[ 'message' ] ?? null,
                    'glows'               => $payload[ 'glows' ] ?? null,
                    'solutions'           => $payload[ 'solutions' ] ?? null,
                    'documentation_links' => $payload[ 'documentation_links' ] ?? null,
                    'stacktrace'          => $payload[ 'stacktrace' ] ?? null,
                    'context'             => $payload[ 'context' ] ?? null,
                    'stage'               => $payload[ 'stage' ] ?? null,
                    'message_level'       => $payload[ 'message_level' ] ?? null,
                    'open_frame_index'    => $payload[ 'open_frame_index' ] ?? null,
                    'application_path'    => $payload[ 'application_path' ] ?? null,
                    'application_version' => $payload[ 'application_version' ] ?? null,
                    'tracking_uuid'       => $payload[ 'tracking_uuid' ] ?? null,
                    'handled'             => $payload[ 'handled' ] ?? null,
                    'overridden_grouping' => $payload[ 'overridden_grouping' ] ?? null,
                ]);
            } catch ( \Exception $exception ) {
                Log::error($exception->getMessage());
            }

            return response()->json([]);
        }

        /**
         * Display the specified resource.
         */
        public function show( ErrorReportingSystemController $errorReportingSystem )
        {
            //
        }

        /**
         * Update the specified resource in storage.
         */
        public function update( Request $request, ErrorReportingSystemController $errorReportingSystem )
        {
            //
        }

        /**
         * Remove the specified resource from storage.
         */
        public function destroy( ErrorReportingSystemController $errorReportingSystem )
        {
            //
        }
    }
