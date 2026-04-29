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
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"name", "environment", "identity_endpoint"},
     *
     *             @OA\Property(property="app_id", type="integer", nullable=true, example=123),
     *             @OA\Property(property="name", type="string", example="Acme Production"),
     *             @OA\Property(property="environment", type="string", example="production"),
     *             @OA\Property(property="identity_endpoint", type="string", format="uri", example="https://app.example.com/checkybot/identity"),
     *             @OA\Property(property="technology", type="string", nullable=true, example="laravel"),
     *             @OA\Property(property="package_version", type="string", nullable=true, example="1.2.3")
     *         )
     *     ),
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
     *     summary="Sync package-managed API, uptime, and SSL checks",
     *     description="The package payload is the source of truth for package-managed checks. Matching checks are overwritten on each sync, package-managed website descriptions are reset from package data, missing package checks are disabled, and uptime plus SSL may share one key when they describe the same website.",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"project", "checks"},
     *
     *             @OA\Property(
     *                 property="project",
     *                 type="object",
     *                 required={"key", "name", "environment", "base_url"},
     *                 @OA\Property(property="key", type="string", example="acme-production"),
     *                 @OA\Property(property="name", type="string", example="Acme Production"),
     *                 @OA\Property(property="environment", type="string", example="production"),
     *                 @OA\Property(property="base_url", type="string", format="uri", example="https://app.example.com"),
     *                 @OA\Property(property="repository", type="string", nullable=true, example="marin/acme")
     *             ),
     *             @OA\Property(
     *                 property="defaults",
     *                 type="object",
     *                 nullable=true,
     *                 @OA\Property(property="headers", type="object", nullable=true, additionalProperties=@OA\AdditionalProperties(type="string", nullable=true)),
     *                 @OA\Property(property="timeout_seconds", type="integer", nullable=true, minimum=1, maximum=120, example=15)
     *             ),
     *             @OA\Property(
     *                 property="checks",
     *                 type="array",
     *                 maxItems=200,
     *
     *                 @OA\Items(
     *                     oneOf={
     *
     *                         @OA\Schema(
     *                             type="object",
     *                             required={"key", "type", "name", "method", "url"},
     *
     *                             @OA\Property(property="key", type="string", example="health"),
     *                             @OA\Property(property="type", type="string", enum={"api"}, example="api"),
     *                             @OA\Property(property="name", type="string", example="Health endpoint"),
     *                             @OA\Property(property="method", type="string", enum={"GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"}, example="GET"),
     *                             @OA\Property(property="url", type="string", example="/health"),
     *                             @OA\Property(property="headers", type="object", nullable=true, additionalProperties=@OA\AdditionalProperties(type="string", nullable=true)),
     *                             @OA\Property(property="request_body_type", type="string", nullable=true, enum={"json", "form", "raw"}, example="json"),
     *                             @OA\Property(property="request_body", nullable=true, example={"email": "monitor@example.com", "password": "secret"}),
     *                             @OA\Property(property="expected_status", type="integer", nullable=true, minimum=100, maximum=599, example=200),
     *                             @OA\Property(property="timeout_seconds", type="integer", nullable=true, minimum=1, maximum=120, example=10),
     *                             @OA\Property(property="save_failed_response", type="boolean", nullable=true, example=false, description="Set false to avoid storing failed response bodies for this API check."),
     *                             @OA\Property(property="schedule", type="string", nullable=true, example="5m"),
     *                             @OA\Property(property="enabled", type="boolean", nullable=true, example=true),
     *                             @OA\Property(
     *                                 property="assertions",
     *                                 type="array",
     *                                 nullable=true,
     *                                 maxItems=50,
     *
     *                                 @OA\Items(
     *                                     type="object",
     *                                     required={"type", "path"},
     *
     *                                     @OA\Property(property="type", type="string", enum={"json_path_exists", "json_path_not_exists", "json_path_equals", "exists", "not_exists", "value_compare", "type_check", "array_length", "regex_match"}),
     *                                     @OA\Property(property="path", type="string", example="$.status"),
     *                                     @OA\Property(property="expected_value", nullable=true),
     *                                     @OA\Property(property="expected_type", type="string", nullable=true),
     *                                     @OA\Property(property="comparison_operator", type="string", nullable=true, enum={"=", "!=", ">", ">=", "<", "<=", "contains"}),
     *                                     @OA\Property(property="regex_pattern", type="string", nullable=true)
     *                                 )
     *                             )
     *                         ),
     *
     *                         @OA\Schema(
     *                             type="object",
     *                             required={"key", "type", "name", "url", "schedule"},
     *
     *                             @OA\Property(property="key", type="string", example="homepage"),
     *                             @OA\Property(property="type", type="string", enum={"ssl", "uptime"}, example="uptime"),
     *                             @OA\Property(property="name", type="string", example="Homepage"),
     *                             @OA\Property(property="method", type="string", nullable=true, enum={"GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"}),
     *                             @OA\Property(property="url", type="string", example="https://app.example.com"),
     *                             @OA\Property(property="headers", type="object", nullable=true, additionalProperties=@OA\AdditionalProperties(type="string", nullable=true)),
     *                             @OA\Property(property="expected_status", type="integer", nullable=true, minimum=100, maximum=599, example=200),
     *                             @OA\Property(property="timeout_seconds", type="integer", nullable=true, minimum=1, maximum=120, example=10),
     *                             @OA\Property(property="schedule", type="string", example="5m"),
     *                             @OA\Property(property="enabled", type="boolean", nullable=true, example=true)
     *                         )
     *                     }
     *                 )
     *             )
     *         )
     *     ),
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
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="uptime_checks",
     *                 type="array",
     *                 maxItems=100,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name", "url", "interval"},
     *
     *                     @OA\Property(property="name", type="string", example="Homepage"),
     *                     @OA\Property(property="url", type="string", format="uri", example="https://app.example.com"),
     *                     @OA\Property(property="interval", type="string", pattern="^\d+[mhd]$", example="5m"),
     *                     @OA\Property(property="max_redirects", type="integer", minimum=0, maximum=20, example=5)
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="ssl_checks",
     *                 type="array",
     *                 maxItems=100,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name", "url", "interval"},
     *
     *                     @OA\Property(property="name", type="string", example="Certificate"),
     *                     @OA\Property(property="url", type="string", format="uri", example="https://app.example.com"),
     *                     @OA\Property(property="interval", type="string", pattern="^\d+[mhd]$", example="1d")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="api_checks",
     *                 type="array",
     *                 maxItems=100,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name", "url", "interval"},
     *
     *                     @OA\Property(property="name", type="string", example="Health endpoint"),
     *                     @OA\Property(property="url", type="string", format="uri", example="https://app.example.com/health"),
     *                     @OA\Property(property="interval", type="string", pattern="^\d+[mhd]$", example="5m"),
     *                     @OA\Property(property="headers", type="object"),
     *                     @OA\Property(property="request_body_type", type="string", nullable=true, enum={"json", "form", "raw"}, example="json"),
     *                     @OA\Property(property="request_body", nullable=true, example={"email": "monitor@example.com", "password": "secret"}),
     *                     @OA\Property(property="save_failed_response", type="boolean", nullable=true, example=false, description="Set false to avoid storing failed response bodies for this API check."),
     *                     @OA\Property(
     *                         property="assertions",
     *                         type="array",
     *
     *                         @OA\Items(
     *                             type="object",
     *                             required={"data_path", "assertion_type"},
     *
     *                             @OA\Property(property="data_path", type="string", example="status"),
     *                             @OA\Property(property="assertion_type", type="string", enum={"exists", "not_exists", "type_check", "value_compare", "array_length", "regex_match"}),
     *                             @OA\Property(property="expected_type", type="string", nullable=true),
     *                             @OA\Property(property="comparison_operator", type="string", nullable=true, enum={"=", "!=", ">", ">=", "<", "<=", "contains"}),
     *                             @OA\Property(property="expected_value", type="string", nullable=true),
     *                             @OA\Property(property="regex_pattern", type="string", nullable=true),
     *                             @OA\Property(property="sort_order", type="integer", minimum=1),
     *                             @OA\Property(property="is_active", type="boolean")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Project checks synced"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=403, description="Project is not owned by the API key user"),
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
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"declared_components", "components"},
     *
     *             @OA\Property(
     *                 property="declared_components",
     *                 type="array",
     *                 maxItems=100,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name", "interval"},
     *
     *                     @OA\Property(property="name", type="string", example="Database"),
     *                     @OA\Property(property="interval", type="string", pattern="^\d+[mhd]$", example="5m")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="components",
     *                 type="array",
     *                 maxItems=100,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name", "interval", "status", "observed_at"},
     *
     *                     @OA\Property(property="name", type="string", example="Database"),
     *                     @OA\Property(property="interval", type="string", pattern="^\d+[mhd]$", example="5m"),
     *                     @OA\Property(property="status", type="string", enum={"healthy", "warning", "danger"}, example="healthy"),
     *                     @OA\Property(property="summary", type="string", nullable=true, example="Replication lag is normal"),
     *                     @OA\Property(
     *                         property="metrics",
     *                         nullable=true,
     *                         oneOf={
     *
     *                             @OA\Schema(type="object"),
     *                             @OA\Schema(type="array", @OA\Items())
     *                         }
     *                     ),
     *
     *                     @OA\Property(property="observed_at", type="string", format="date-time", example="2026-04-22T07:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Project components synced"),
     *     @OA\Response(response=401, description="Invalid API key"),
     *     @OA\Response(response=403, description="Project is not owned by the API key user"),
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
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"name", "url"},
     *
     *             @OA\Property(property="type", type="string", nullable=true, enum={"api"}, example="api"),
     *             @OA\Property(property="name", type="string", example="Health endpoint"),
     *             @OA\Property(property="method", type="string", nullable=true, enum={"GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"}, example="GET"),
     *             @OA\Property(property="url", type="string", example="/health"),
     *             @OA\Property(property="headers", type="object", nullable=true, additionalProperties=@OA\AdditionalProperties(type="string", nullable=true)),
     *             @OA\Property(property="expected_status", type="integer", nullable=true, minimum=100, maximum=599, example=200),
     *             @OA\Property(property="timeout_seconds", type="integer", nullable=true, minimum=1, maximum=120, example=10),
     *             @OA\Property(property="schedule", type="string", nullable=true, example="5m"),
     *             @OA\Property(property="enabled", type="boolean", nullable=true, example=true),
     *             @OA\Property(
     *                 property="assertions",
     *                 type="array",
     *                 nullable=true,
     *                 maxItems=50,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"type", "path"},
     *
     *                     @OA\Property(property="type", type="string", enum={"json_path_exists", "json_path_not_exists", "json_path_equals", "exists", "not_exists", "value_compare", "type_check", "array_length", "regex_match"}),
     *                     @OA\Property(property="path", type="string", example="$.status"),
     *                     @OA\Property(property="expected_value", nullable=true),
     *                     @OA\Property(property="expected_type", type="string", nullable=true),
     *                     @OA\Property(property="comparison_operator", type="string", nullable=true, enum={"=", "!=", ">", ">=", "<", "<=", "contains"}),
     *                     @OA\Property(property="regex_pattern", type="string", nullable=true),
     *                     @OA\Property(property="sort_order", type="integer", nullable=true, minimum=1),
     *                     @OA\Property(property="active", type="boolean", nullable=true)
     *                 )
     *             )
     *         )
     *     ),
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
     *     summary="Run diagnostic checks for all enabled checks in a project",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Diagnostic project run completed"),
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
     *     summary="Run one package-managed API check as a diagnostic",
     *     security={{"checkybotApiKey": {}}},
     *
     *     @OA\Parameter(name="project", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="check", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Diagnostic check run completed"),
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
     *             type="object",
     *             required={"jsonrpc", "method"},
     *
     *             @OA\Property(property="jsonrpc", type="string", enum={"2.0"}, example="2.0"),
     *             @OA\Property(
     *                 property="id",
     *                 nullable=true,
     *                 oneOf={
     *
     *                     @OA\Schema(type="string"),
     *                     @OA\Schema(type="number")
     *                 },
     *                 example="request-1"
     *             ),
     *
     *             @OA\Property(property="method", type="string", example="tools/list"),
     *             @OA\Property(
     *                 property="params",
     *                 nullable=true,
     *                 oneOf={
     *
     *                     @OA\Schema(type="object"),
     *                     @OA\Schema(type="array", @OA\Items())
     *                 }
     *             )
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
