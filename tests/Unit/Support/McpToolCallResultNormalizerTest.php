<?php

use App\Support\McpToolCallResultNormalizer;

test('mcp tool results wrap list structured content as a record and sync the first text item', function () {
    $normalized = McpToolCallResultNormalizer::normalize([
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
    $normalized = McpToolCallResultNormalizer::normalize([
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

    expect(McpToolCallResultNormalizer::normalize($toolResult))->toBe($toolResult);
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

    expect(McpToolCallResultNormalizer::normalize($toolResult))->toBe($toolResult);
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

    expect(McpToolCallResultNormalizer::normalize($toolResult))->toBe($toolResult);
});
