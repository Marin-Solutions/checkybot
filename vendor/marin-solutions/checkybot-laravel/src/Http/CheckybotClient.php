<?php

namespace MarinSolutions\CheckybotLaravel\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;

class CheckybotClient
{
    protected Client $client;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected ?string $projectId = null,
        protected int $timeout = 30,
        protected int $retryTimes = 3,
        protected int $retryDelay = 1000,
        ?Client $client = null
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     */
    public function registerApplication(array $payload): array
    {
        $response = $this->post('/api/v1/package/register', $payload, 'Checkybot application registration successful');
        $projectId = data_get($response, 'data.project_id');

        if ($projectId === null) {
            throw new CheckybotSyncException('Checkybot registration did not return a project id');
        }

        $this->projectId = (string) $projectId;

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     */
    public function syncChecks(array $payload): array
    {
        return $this->post(
            sprintf('/api/v1/projects/%s/checks/sync', $this->requireProjectId()),
            $payload,
            'Checkybot check sync successful'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     */
    public function syncComponents(array $payload): array
    {
        return $this->post(
            sprintf('/api/v1/projects/%s/components/sync', $this->requireProjectId()),
            $payload,
            'Checkybot component sync successful'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     */
    protected function post(string $url, array $payload, string $message): array
    {
        try {
            $response = $this->client->post($url, [
                'json' => $payload,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->apiKey,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $body = json_decode($response->getBody()->getContents(), true);
                throw new CheckybotSyncException(
                    $this->formatErrorMessage($body),
                    $statusCode
                );
            }

            $body = json_decode($response->getBody()->getContents(), true) ?? [];

            Log::info($message, [
                'project_id' => $this->projectId,
                'summary' => $body['summary'] ?? null,
            ]);

            return $body;
        } catch (GuzzleException $e) {
            $errorMessage = $this->parseErrorMessage($e);

            Log::error('Checkybot sync failed', [
                'project_id' => $this->projectId,
                'error' => $errorMessage,
                'status_code' => $e->getCode(),
            ]);

            throw new CheckybotSyncException($errorMessage, (int) $e->getCode(), $e);
        }
    }

    protected function parseErrorMessage(GuzzleException $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);

            return $this->formatErrorMessage($body);
        }

        return $e->getMessage();
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function formatErrorMessage(?array $body): string
    {
        if (isset($body['errors'])) {
            return 'Validation failed: '.json_encode($body['errors']);
        }

        return $body['message'] ?? 'Unknown error occurred';
    }

    /**
     * @throws CheckybotSyncException
     */
    protected function requireProjectId(): string
    {
        if (blank($this->projectId)) {
            throw new CheckybotSyncException('Checkybot project id has not been resolved');
        }

        return $this->projectId;
    }
}
