<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkybot\ListControlFailuresRequest;
use App\Http\Requests\Checkybot\ListControlRunsRequest;
use App\Http\Requests\Checkybot\UpsertControlCheckRequest;
use App\Models\ApiKey;
use App\Services\CheckybotControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckybotControlController extends Controller
{
    public function __construct(
        private readonly CheckybotControlService $control,
    ) {}

    public function me(Request $request): JsonResponse
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('checkybot_api_key');

        return response()->json([
            'data' => $this->control->me($request->user(), $apiKey?->name),
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->control->listProjects($request->user()),
        ]);
    }

    public function project(Request $request, string $project): JsonResponse
    {
        return response()->json([
            'data' => $this->control->getProject($request->user(), $project),
        ]);
    }

    public function checks(Request $request, string $project): JsonResponse
    {
        return response()->json([
            'data' => $this->control->listChecks($request->user(), $project),
        ]);
    }

    public function upsertCheck(UpsertControlCheckRequest $request, string $project, string $check): JsonResponse
    {
        $data = $request->validated();
        $data['key'] = $check;

        $result = $this->control->upsertCheck($request->user(), $project, $data);

        return response()->json([
            'message' => $result['created'] ? 'Check created.' : 'Check updated.',
            'data' => $result,
        ], $result['created'] ? 201 : 200);
    }

    public function disableCheck(Request $request, string $project, string $check): JsonResponse
    {
        return response()->json([
            'message' => 'Check disabled.',
            'data' => $this->control->disableCheck($request->user(), $project, $check),
        ]);
    }

    public function triggerProjectRun(Request $request, string $project): JsonResponse
    {
        return response()->json([
            'message' => 'Project run completed.',
            'data' => $this->control->triggerProjectRun($request->user(), $project),
        ]);
    }

    public function triggerCheckRun(Request $request, string $project, string $check): JsonResponse
    {
        return response()->json([
            'message' => 'Check run completed.',
            'data' => $this->control->triggerCheckRun($request->user(), $project, $check),
        ]);
    }

    public function runs(ListControlRunsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->control->recentRuns(
                $request->user(),
                $data['project'] ?? null,
                $data['limit'] ?? 25,
            ),
        ]);
    }

    public function projectRuns(ListControlRunsRequest $request, string $project): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->control->recentRuns($request->user(), $project, $data['limit'] ?? 25),
        ]);
    }

    public function failures(ListControlFailuresRequest $request): JsonResponse
    {
        $data = $request->validated();

        $project = isset($data['project'])
            ? $this->control->findProject($request->user(), $data['project'])
            : null;

        return response()->json([
            'data' => $this->control->latestFailures($request->user(), $project, $data['limit'] ?? 25),
        ]);
    }

    public function projectFailures(ListControlFailuresRequest $request, string $project): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->control->latestFailures(
                $request->user(),
                $this->control->findProject($request->user(), $project),
                $data['limit'] ?? 25,
            ),
        ]);
    }
}
