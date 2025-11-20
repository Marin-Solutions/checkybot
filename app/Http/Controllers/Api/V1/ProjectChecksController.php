<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncProjectChecksRequest;
use App\Models\Project;
use App\Services\CheckSyncService;
use Illuminate\Http\JsonResponse;

class ProjectChecksController extends Controller
{
    public function __construct(
        protected CheckSyncService $checkSyncService
    ) {}

    public function sync(SyncProjectChecksRequest $request, Project $project): JsonResponse
    {
        try {
            $summary = $this->checkSyncService->syncChecks(
                $project,
                $request->validated()
            );

            return response()->json([
                'message' => 'Checks synced successfully',
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to sync checks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
