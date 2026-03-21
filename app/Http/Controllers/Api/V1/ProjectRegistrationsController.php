<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterProjectRequest;
use App\Models\User;
use App\Services\ProjectRegistrationService;
use Illuminate\Http\JsonResponse;

class ProjectRegistrationsController extends Controller
{
    public function __construct(
        protected ProjectRegistrationService $projectRegistrationService
    ) {}

    public function __invoke(RegisterProjectRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $registration = $this->projectRegistrationService->register(
            $user,
            $request->validated()
        );

        $status = $registration['created'] ? 201 : 200;

        return response()->json([
            'message' => 'Project registered successfully',
            'data' => [
                'project_id' => $registration['project']->id,
                'created' => $registration['created'],
            ],
        ], $status);
    }
}
