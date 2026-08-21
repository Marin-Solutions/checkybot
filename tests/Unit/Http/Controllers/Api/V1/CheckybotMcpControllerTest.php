<?php

use App\Http\Controllers\Api\V1\CheckybotMcpController;

beforeEach(function () {
    $this->controller = app(CheckybotMcpController::class);

    $this->normalizeToolCallResult = function (array $toolResult): array {
        $normalizer = new ReflectionMethod(CheckybotMcpController::class, 'normalizeToolCallResult');
        $normalizer->setAccessible(true);

        return $normalizer->invoke($this->controller, $toolResult);
    };
});

test('mcp tool results wrap list structured content as a record and sync the first text item', function () {
    $normalized = ($this->normalizeToolCallResult)([
        'content' => [
            [
                'type' => 'text',
                'text' => '[{"key":"scrappa"}]',
            ],
        ],
        'structuredContent' => [
            ['key' => 'scrappa'],
        ],
    ]);

    expect($normalized['structuredContent'])->toBe(['data' => [['key' => 'scrappa']]])
        ->and(json_decode($normalized['content'][0]['text'], true))->toBe($normalized['structuredContent']);
});

test('mcp tool results wrap non object structured content as a record', function () {
    $normalized = ($this->normalizeToolCallResult)([
        'content' => [
            [
                'type' => 'text',
                'text' => '"pong"',
            ],
        ],
        'structuredContent' => 'pong',
    ]);

    expect($normalized['structuredContent'])->toBe(['data' => 'pong'])
        ->and(json_decode($normalized['content'][0]['text'], true))->toBe($normalized['structuredContent']);
});

test('mcp tool results pass object structured content through untouched', function () {
    $toolResult = [
        'content' => [
            [
                'type' => 'text',
                'text' => '{"authenticated":true}',
            ],
        ],
        'structuredContent' => ['authenticated' => true],
    ];

    expect(($this->normalizeToolCallResult)($toolResult))->toBe($toolResult);
});

test('mcp tool results pass stdClass structured content through untouched', function () {
    $toolResult = [
        'content' => [
            [
                'type' => 'text',
                'text' => '{}',
            ],
        ],
        'structuredContent' => new stdClass,
    ];

    expect(($this->normalizeToolCallResult)($toolResult))->toBe($toolResult);
});

test('mcp tool results without structured content pass through untouched', function () {
    $toolResult = [
        'content' => [
            [
                'type' => 'text',
                'text' => 'ok',
            ],
        ],
    ];

    expect(($this->normalizeToolCallResult)($toolResult))->toBe($toolResult);
});
