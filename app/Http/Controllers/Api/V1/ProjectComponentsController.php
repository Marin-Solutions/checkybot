<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncProjectComponentsRequest;
use App\Models\Project;
use App\Services\ProjectComponentSyncService;
use Illuminate\Http\JsonResponse;

class ProjectComponentsController extends Controller
{
    public function __construct(
        protected ProjectComponentSyncService $projectComponentSyncService
    ) {}

    public function __invoke(SyncProjectComponentsRequest $request, Project $project): JsonResponse
    {
        $summary = $this->projectComponentSyncService->sync(
            $project,
            $request->validated('components', [])
        );

        return response()->json([
            'message' => 'Components synced successfully',
            'summary' => $summary,
        ]);
    }
}
