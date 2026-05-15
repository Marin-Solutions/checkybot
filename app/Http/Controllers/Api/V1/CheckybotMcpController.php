<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Rules\RelativeOrHttpUrl;
use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use App\Services\CheckybotControlService;
use App\Services\IntervalParser;
use App\Support\ValidatesMonitorApiRegexAssertions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CheckybotMcpController extends Controller
{
    use ValidatesMonitorApiRegexAssertions;

    public function __construct(
        private readonly CheckybotControlService $control,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'jsonrpc' => ['required', 'in:2.0'],
            'id' => ['nullable'],
            'method' => ['required', 'string'],
            'params' => ['nullable', 'array'],
        ]);

        try {
            $result = match ($payload['method']) {
                'initialize' => $this->initializeResult(),
                'notifications/initialized' => ['ok' => true],
                'ping' => ['ok' => true],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->callTool($request, $payload['params'] ?? []),
                default => $this->jsonRpcError($payload['id'] ?? null, -32601, 'Method not found.'),
            };

            if ($result instanceof JsonResponse) {
                return $result;
            }

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'result' => $result,
            ]);
        } catch (ValidationException $exception) {
            return $this->jsonRpcError($payload['id'] ?? null, -32602, 'Invalid tool arguments.', [
                'errors' => $exception->errors(),
            ]);
        } catch (ModelNotFoundException) {
            return $this->jsonRpcError($payload['id'] ?? null, -32004, 'Requested Checkybot project or check was not found.');
        } catch (HttpExceptionInterface $exception) {
            return $this->jsonRpcError($payload['id'] ?? null, -32000, $exception->getMessage() ?: 'Tool call failed.');
        } catch (\Throwable $exception) {
            report($exception);

            return $this->jsonRpcError($payload['id'] ?? null, -32000, 'Tool call failed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeResult(): array
    {
        return [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [
                'tools' => new \stdClass,
            ],
            'serverInfo' => [
                'name' => 'checkybot-control',
                'version' => '1.0.0',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(Request $request, array $params): array
    {
        $call = Validator::make($params, [
            'name' => ['required', 'string'],
            'arguments' => ['nullable', 'array'],
        ])->validate();

        $arguments = $call['arguments'] ?? [];
        $user = $request->user();

        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('checkybot_api_key');

        $result = match ($call['name']) {
            'me',
            'checkybot_me' => $this->control->me($user, $apiKey?->name),
            'list_projects',
            'checkybot_list_projects' => $this->control->listProjects($user),
            'create_project',
            'checkybot_create_project' => $this->control->createProject($user, $this->validateProjectArguments($arguments)),
            'get_project',
            'checkybot_get_project' => $this->control->getProject($user, $this->requiredString($arguments, 'project')),
            'list_checks',
            'checkybot_list_checks' => $this->control->listChecks($user, $this->requiredString($arguments, 'project')),
            'upsert_check',
            'checkybot_upsert_check' => $this->control->upsertCheck(
                $user,
                $this->requiredString($arguments, 'project'),
                $this->validateCheckArguments($arguments),
            ),
            'disable_check',
            'checkybot_disable_check' => $this->control->disableCheck(
                $user,
                $this->requiredString($arguments, 'project'),
                $this->requiredString($arguments, 'check'),
                $this->optionalCheckType($arguments),
            ),
            'trigger_run',
            'checkybot_trigger_run' => isset($arguments['check'])
                ? $this->control->triggerCheckRun(
                    $user,
                    $this->requiredString($arguments, 'project'),
                    $this->requiredString($arguments, 'check'),
                    $this->optionalRunnableCheckType($arguments),
                )
                : $this->control->triggerProjectRun($user, $this->requiredString($arguments, 'project')),
            'get_run_batch',
            'checkybot_get_run_batch' => $this->control->projectRunBatch(
                $user,
                $this->requiredString($arguments, 'project'),
                $this->requiredString($arguments, 'batch'),
            ),
            'recent_runs',
            'checkybot_recent_runs' => $this->recentRuns($request, $arguments),
            'latest_failures',
            'checkybot_latest_failures' => $this->latestFailures($request, $arguments),
            default => throw ValidationException::withMessages(['name' => ['Unknown Checkybot MCP tool.']]),
        };

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'structuredContent' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function recentRuns(Request $request, array $arguments): array
    {
        return $this->control->recentRuns(
            $request->user(),
            isset($arguments['project']) ? $this->requiredString($arguments, 'project') : null,
            (int) ($arguments['limit'] ?? 25),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function latestFailures(Request $request, array $arguments): array
    {
        $project = isset($arguments['project'])
            ? $this->control->findProject($request->user(), $this->requiredString($arguments, 'project'))
            : null;

        return $this->control->latestFailures($request->user(), $project, (int) ($arguments['limit'] ?? 25));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredString(array $arguments, string $key): string
    {
        return Validator::make($arguments, [
            $key => ['required', 'string', 'max:255'],
        ])->validate()[$key];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function optionalCheckType(array $arguments): ?string
    {
        return Validator::make($arguments, [
            'type' => ['nullable', Rule::in(['api', 'website', 'component'])],
        ])->validate()['type'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function optionalRunnableCheckType(array $arguments): ?string
    {
        return Validator::make($arguments, [
            'type' => ['nullable', Rule::in(['api', 'website'])],
        ])->validate()['type'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function validateCheckArguments(array $arguments): array
    {
        if (isset($arguments['url']) && is_string($arguments['url'])) {
            $arguments['url'] = trim($arguments['url']);
        }

        $validator = Validator::make($arguments, [
            'key' => ['required', 'string', 'alpha_dash', 'max:150'],
            'type' => ['nullable', Rule::in(['api', 'website'])],
            'check_types' => ['nullable', 'array', 'min:1', 'max:2'],
            'check_types.*' => ['required', 'string', Rule::in(['uptime', 'ssl'])],
            'name' => ['required', 'string', 'max:255'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'url' => ['required', 'string', 'max:1000', new RelativeOrHttpUrl],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:2000'],
            'request_body_type' => [new RequestBodyTypeRequired, 'nullable', 'string', Rule::in(['json', 'form', 'raw'])],
            'request_body' => ['nullable', new RequestBodyMaxSize, new StructuredRequestBody],
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
            'schedule' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && (! is_string($value) || ! IntervalParser::isValid($value))) {
                    $fail('The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.');
                }
            }],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $validator->after(function (ValidationValidator $validator) use ($arguments): void {
            $assertions = $arguments['assertions'] ?? [];

            if (! is_array($assertions)) {
                return;
            }

            $this->addRegexAssertionValidationErrors($validator, $assertions, 'assertions');
        });

        $data = $validator->validate();

        if (array_key_exists('schedule', $arguments) && blank($data['schedule'] ?? null)) {
            $data['schedule'] = IntervalParser::DEFAULT_API_INTERVAL;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function validateProjectArguments(array $arguments): array
    {
        return Validator::make($arguments, [
            'key' => ['required', 'string', 'alpha_dash', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:1000'],
            'repository' => ['nullable', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'technology' => ['nullable', 'string', 'max:255'],
            'identity_endpoint' => ['nullable', 'url', 'max:1000'],
            'package_version' => ['nullable', 'string', 'max:50'],
        ])->validate();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            $this->tool('me', 'Verify Checkybot API authentication and app version.', []),
            $this->tool('list_projects', 'List Checkybot projects visible to the API key.', []),
            $this->tool('create_project', 'Create or update a Checkybot project so checks can be synced or managed by project key.', [
                'key' => ['type' => 'string', 'description' => 'Stable package key used as the project identifier.'],
                'name' => ['type' => 'string'],
                'environment' => ['type' => 'string'],
                'base_url' => ['type' => 'string', 'format' => 'uri'],
                'repository' => ['type' => 'string'],
                'group' => ['type' => 'string'],
                'technology' => ['type' => 'string'],
                'identity_endpoint' => ['type' => 'string', 'format' => 'uri', 'description' => 'Defaults to base_url when omitted.'],
                'package_version' => ['type' => 'string'],
            ], ['key', 'name', 'environment', 'base_url']),
            $this->tool('get_project', 'Get project detail, API/website/component check counts, health counts, and latest failure.', [
                'project' => ['type' => 'string', 'description' => 'Project id or package key.'],
            ]),
            $this->tool('list_checks', 'List package-managed API checks, website checks, and component declarations for a project. Component status is derived from linked active API and website checks and supports_run=false.', [
                'project' => ['type' => 'string', 'description' => 'Project id or package key.'],
            ]),
            $this->tool('upsert_check', 'Create or update a package-managed API or website check by stable key.', [
                'project' => ['type' => 'string'],
                'key' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['api', 'website'], 'default' => 'api'],
                'check_types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['uptime', 'ssl']], 'description' => 'Website checks only. Defaults to the existing enabled website check types, or uptime for new website checks.'],
                'name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'method' => ['type' => 'string', 'default' => 'GET'],
                'headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'request_body_type' => ['type' => 'string', 'enum' => ['json', 'form', 'raw']],
                'request_body' => ['description' => 'JSON object/array for json or form body types, or a string for raw bodies.'],
                'expected_status' => ['type' => 'integer', 'default' => 200],
                'timeout_seconds' => ['type' => 'integer'],
                'schedule' => ['type' => 'string', 'default' => IntervalParser::DEFAULT_API_INTERVAL],
                'enabled' => ['type' => 'boolean'],
                'assertions' => ['type' => 'array', 'items' => ['type' => 'object']],
            ], ['project', 'key', 'name', 'url']),
            $this->tool('disable_check', 'Disable a check without deleting its definition or history.', [
                'project' => ['type' => 'string'],
                'check' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['api', 'website', 'component'], 'description' => 'Optional check type. Required when multiple check surfaces share the same key.'],
            ]),
            $this->tool('trigger_run', 'Queue enabled API and website diagnostics for a project, or run a single API or website check immediately. Components are declarations and are not runnable from MCP.', [
                'project' => ['type' => 'string'],
                'check' => ['type' => 'string', 'description' => 'Optional check key.'],
                'type' => ['type' => 'string', 'enum' => ['api', 'website'], 'description' => 'Optional runnable check type. Required when an API and website share the same key.'],
            ], ['project']),
            $this->tool('get_run_batch', 'Get queued project diagnostic batch status for a batch returned by trigger_run. Returns project identity plus run_batch id, status, name, total_jobs, pending_jobs, failed_jobs, created_at, and finished_at.', [
                'project' => ['type' => 'string', 'description' => 'Project id or package key.'],
                'batch' => ['type' => 'string', 'description' => 'Laravel batch id returned by trigger_run.'],
            ], ['project', 'batch']),
            $this->tool('recent_runs', 'List recent API and website runs executed by Checkybot.', [
                'project' => ['type' => 'string', 'description' => 'Optional project id or package key.'],
                'limit' => ['type' => 'integer', 'default' => 25],
            ]),
            $this->tool('latest_failures', 'List latest warning or danger API and website runs executed by Checkybot.', [
                'project' => ['type' => 'string', 'description' => 'Optional project id or package key.'],
                'limit' => ['type' => 'integer', 'default' => 25],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function jsonRpcError(mixed $id, int $code, string $message, ?array $data = null): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ], fn ($value): bool => $value !== null),
        ]);
    }
}
