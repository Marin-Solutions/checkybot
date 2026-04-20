<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackageSyncRequest;
use App\Models\User;
use App\Services\PackageSyncService;
use Illuminate\Http\JsonResponse;

class PackageSyncController extends Controller
{
    public function __construct(
        protected PackageSyncService $packageSyncService
    ) {}

    public function __invoke(PackageSyncRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->packageSyncService->sync($user, $request->validated());
        $project = $result['project'];

        return response()->json([
            'message' => 'Package sync accepted',
            'data' => [
                'project' => [
                    'id' => $project->id,
                    'key' => $project->package_key,
                ],
                'summary' => $result['summary'],
                'synced_at' => $result['synced_at']->toISOString(),
            ],
        ], $result['project_created'] ? 201 : 200);
    }
}
