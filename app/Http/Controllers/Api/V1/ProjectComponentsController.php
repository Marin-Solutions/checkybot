<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportProjectComponentStatusRequest;
use App\Http\Requests\SyncProjectComponentsRequest;
use App\Models\Project;
use App\Services\ProjectComponentStatusService;
use App\Services\ProjectComponentSyncService;
use Illuminate\Http\JsonResponse;

class ProjectComponentsController extends Controller
{
    public function __construct(
        protected ProjectComponentSyncService $projectComponentSyncService,
        protected ProjectComponentStatusService $projectComponentStatusService
    ) {}

    public function __invoke(SyncProjectComponentsRequest $request, Project $project): JsonResponse
    {
        $payload = $request->validated();

        $summary = $this->projectComponentSyncService->sync(
            $project,
            $payload,
        );

        return response()->json([
            'message' => 'Components synced successfully',
            'summary' => $summary,
        ]);
    }

    public function status(
        ReportProjectComponentStatusRequest $request,
        Project $project,
        string $componentKey
    ): JsonResponse {
        $result = $this->projectComponentStatusService->report(
            project: $project,
            componentKey: $componentKey,
            status: $request->string('status')->toString(),
            observedAt: $request->string('observed_at')->toString(),
            message: $request->string('message')->toString(),
            metrics: $request->input('metrics'),
            idempotencyKey: $request->idempotencyKey(),
        );

        return response()->json([
            'message' => 'Component status reported successfully',
            'component' => $result,
        ]);
    }
}
