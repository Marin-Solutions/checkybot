<?php

    namespace App\Http\Controllers;

    use App\Models\ErrorReportingSystem;
    use Illuminate\Http\Request;
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
            try {
                ErrorReportingSystem::create([
                    'body'    => json_encode($request->getContent()),
                    'headers' => json_encode(request()->headers->all())
                ]);
            } catch ( \Exception $exception ) {
                Log::error($exception->getMessage());
            }

            return response()->json([
                'body' => "I'm buddy"
            ]);
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
