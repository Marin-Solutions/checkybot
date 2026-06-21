<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncProjectChecksRequest;
use App\Models\Project;
use App\Services\CheckSyncService;
use App\Services\CheckybotImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectChecksController extends Controller
{
    public function __construct(
        protected CheckSyncService $checkSyncService,
        protected CheckybotImportService $importService,
    ) {}

    public function project(Request $request, string $project): JsonResponse
    {
        return response()->json([
            'data' => $this->importService->getProject($request->user(), $project),
        ]);
    }

    public function index(Request $request, string $project): JsonResponse
    {
        return response()->json([
            'data' => $this->importService->listChecks($request->user(), $project),
        ]);
    }

    public function show(Request $request, string $project, string $check): JsonResponse
    {
        $data = $request->validate([
            'type' => ['string', 'in:api,uptime,ssl,component'],
        ]);

        return response()->json([
            'data' => $this->importService->getCheck(
                $request->user(),
                $project,
                $check,
                $data['type'] ?? null,
            ),
        ]);
    }

    public function results(Request $request, string $project, string $check): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['integer', 'min:1', 'max:100'],
            'run_source' => ['string', 'in:scheduled,on_demand,all'],
            'type' => ['string', 'in:api,uptime,ssl,component'],
        ]);

        return response()->json([
            'data' => $this->importService->recentResults(
                $request->user(),
                $project,
                $check,
                $data['limit'] ?? 25,
                $data['run_source'] ?? 'all',
                $data['type'] ?? null,
            ),
        ]);
    }

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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to sync checks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
