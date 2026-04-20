<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\CheckybotControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function upsertCheck(Request $request, string $project, string $check): JsonResponse
    {
        $data = $request->validate($this->checkRules());
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
        ], 202);
    }

    public function triggerCheckRun(Request $request, string $project, string $check): JsonResponse
    {
        return response()->json([
            'message' => 'Check run completed.',
            'data' => $this->control->triggerCheckRun($request->user(), $project, $check),
        ], 202);
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->control->recentRuns(
                $request->user(),
                $data['project'] ?? null,
                $data['limit'] ?? 25,
            ),
        ]);
    }

    public function projectRuns(Request $request, string $project): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->control->recentRuns($request->user(), $project, $data['limit'] ?? 25),
        ]);
    }

    public function failures(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $project = isset($data['project'])
            ? $this->control->findProject($request->user(), $data['project'])
            : null;

        return response()->json([
            'data' => $this->control->latestFailures($request->user(), $project, $data['limit'] ?? 25),
        ]);
    }

    public function projectFailures(Request $request, string $project): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->control->latestFailures(
                $request->user(),
                $this->control->findProject($request->user(), $project),
                $data['limit'] ?? 25,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['api'])],
            'name' => ['required', 'string', 'max:255'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'url' => ['required', 'string', 'max:1000'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:2000'],
            'expected_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'assertions' => ['nullable', 'array', 'max:50'],
            'assertions.*.type' => ['required', 'string', Rule::in([
                'json_path_exists',
                'json_path_not_exists',
                'json_path_equals',
                'exists',
                'not_exists',
                'value_compare',
                'type_check',
                'array_length',
                'regex_match',
            ])],
            'assertions.*.path' => ['required', 'string', 'max:500'],
            'assertions.*.expected_value' => ['nullable'],
            'assertions.*.expected_type' => ['nullable', 'string', 'max:50'],
            'assertions.*.comparison_operator' => ['nullable', Rule::in(['=', '!=', '>', '>=', '<', '<=', 'contains'])],
            'assertions.*.regex_pattern' => ['nullable', 'string', 'max:1000'],
            'assertions.*.sort_order' => ['nullable', 'integer', 'min:1'],
            'assertions.*.active' => ['nullable', 'boolean'],
            'schedule' => ['nullable', 'string', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
