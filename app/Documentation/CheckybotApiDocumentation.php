<?php

namespace App\Documentation;

use OpenApi\Annotations as OA;

class CheckybotApiDocumentation
{
    /**
     * @OA\Post(
     *     path="/v1/package/register",
     *     operationId="registerPackageProject",
     *     tags={"package"},
     *     summary="Register a package-managed project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *     @OA\Response(response=200, description="Existing project registration updated"),
     *     @OA\Response(response=201, description="New project registered"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function registerPackageProject(): void {}

    /**
     * @OA\Post(
     *     path="/v1/package/sync",
     *     operationId="syncPackageChecks",
     *     tags={"package"},
     *     summary="Sync package-managed API checks",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *     @OA\Response(response=200, description="Package checks synced"),
     *     @OA\Response(response=201, description="Package checks synced and project created"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function syncPackageChecks(): void {}

    /**
     * @OA\Post(
     *     path="/v1/projects/{project}/checks/sync",
     *     operationId="syncProjectChecks",
     *     tags={"package"},
     *     summary="Sync checks for a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *     @OA\Response(response=200, description="Project checks synced"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function syncProjectChecks(): void {}

    /**
     * @OA\Post(
     *     path="/v1/projects/{project}/components/sync",
     *     operationId="syncProjectComponents",
     *     tags={"package"},
     *     summary="Sync components for a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *     @OA\Response(response=200, description="Project components synced"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function syncProjectComponents(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/me",
     *     operationId="getControlIdentity",
     *     tags={"control"},
     *     summary="Verify API authentication and app identity",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Response(response=200, description="Authenticated identity"),
     *     @OA\Response(response=401, description="Invalid API key")
     * )
     */
    public function controlMe(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/projects",
     *     operationId="listControlProjects",
     *     tags={"control"},
     *     summary="List projects visible to the API key",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Response(response=200, description="Project list"),
     *     @OA\Response(response=401, description="Invalid API key")
     * )
     */
    public function listControlProjects(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/projects/{project}",
     *     operationId="getControlProject",
     *     tags={"control"},
     *     summary="Get project detail by id or package key",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Project detail"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found")
     * )
     */
    public function getControlProject(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/projects/{project}/checks",
     *     operationId="listControlProjectChecks",
     *     tags={"control"},
     *     summary="List package-managed API checks for a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Check list"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found")
     * )
     */
    public function listControlProjectChecks(): void {}

    /**
     * @OA\Put(
     *     path="/v1/control/projects/{project}/checks/{check}",
     *     operationId="upsertControlProjectCheck",
     *     tags={"control"},
     *     summary="Create or update a package-managed API check",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="check", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *     @OA\Response(response=200, description="Check updated"),
     *     @OA\Response(response=201, description="Check created"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function upsertControlProjectCheck(): void {}

    /**
     * @OA\Patch(
     *     path="/v1/control/projects/{project}/checks/{check}/disable",
     *     operationId="disableControlProjectCheck",
     *     tags={"control"},
     *     summary="Disable a package-managed API check",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="check", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Check disabled"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project or check not found")
     * )
     */
    public function disableControlProjectCheck(): void {}

    /**
     * @OA\Post(
     *     path="/v1/control/projects/{project}/runs",
     *     operationId="triggerControlProjectRun",
     *     tags={"control"},
     *     summary="Run all enabled checks for a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Project run completed"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found")
     * )
     */
    public function triggerControlProjectRun(): void {}

    /**
     * @OA\Post(
     *     path="/v1/control/projects/{project}/checks/{check}/runs",
     *     operationId="triggerControlProjectCheckRun",
     *     tags={"control"},
     *     summary="Run one package-managed API check",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="check", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Check run completed"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project or check not found"),
     *     @OA\Response(response=409, description="Check is disabled")
     * )
     */
    public function triggerControlProjectCheckRun(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/runs",
     *     operationId="listControlRuns",
     *     tags={"control"},
     *     summary="List recent check runs",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Recent runs"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function listControlRuns(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/projects/{project}/runs",
     *     operationId="listControlProjectRuns",
     *     tags={"control"},
     *     summary="List recent check runs for a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Recent project runs"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function listControlProjectRuns(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/failures",
     *     operationId="listControlFailures",
     *     tags={"control"},
     *     summary="List recent warning or failing check runs",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Recent failures"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function listControlFailures(): void {}

    /**
     * @OA\Get(
     *     path="/v1/control/projects/{project}/failures",
     *     operationId="listControlProjectFailures",
     *     tags={"control"},
     *     summary="List recent warning or failing check runs for a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Recent project failures"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=404, description="Project not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function listControlProjectFailures(): void {}

    /**
     * @OA\Post(
     *     path="/v1/mcp",
     *     operationId="callCheckybotMcp",
     *     tags={"mcp"},
     *     summary="Call the Checkybot MCP JSON-RPC endpoint",
     *     description="Authenticated JSON-RPC endpoint exposing tools such as me, list_projects, get_project, list_checks, upsert_check, disable_check, trigger_run, and latest_failures.",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"jsonrpc", "method"},
     *
     *             @OA\Property(property="jsonrpc", type="string", example="2.0"),
     *             @OA\Property(property="id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="method", type="string", example="tools/list"),
     *             @OA\Property(property="params", type="object", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="JSON-RPC response"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=422, description="Invalid JSON-RPC request")
     * )
     */
    public function callCheckybotMcp(): void {}
}
