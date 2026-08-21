<?php

namespace App\Support;

class McpToolCallResultNormalizer
{
    /**
     * MCP requires tool-result structuredContent to be a record. List-shaped
     * payloads are wrapped as {"data": ...} and the first text item is
     * re-serialized to match; records and results without structuredContent
     * pass through untouched.
     *
     * @param  array<string, mixed>  $toolResult
     * @return array<string, mixed>
     */
    public static function normalize(array $toolResult): array
    {
        if (! array_key_exists('structuredContent', $toolResult)) {
            return $toolResult;
        }

        $structuredContent = $toolResult['structuredContent'];

        if ($structuredContent instanceof \stdClass
            || (is_array($structuredContent) && ! array_is_list($structuredContent))) {
            return $toolResult;
        }

        $toolResult['structuredContent'] = ['data' => $structuredContent];

        foreach ($toolResult['content'] ?? [] as $index => $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text') {
                $toolResult['content'][$index]['text'] = json_encode(
                    $toolResult['structuredContent'],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );

                break;
            }
        }

        return $toolResult;
    }
}
