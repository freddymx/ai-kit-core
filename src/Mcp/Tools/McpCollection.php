<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Mcp\Tools;

/**
 * Resolves the collection an MCP tool should act on: the explicit argument, or
 * a pinned config('ai-kit.mcp.default_collection'), or the global default.
 */
final class McpCollection
{
    public static function resolve(mixed $collection): string
    {
        if (is_string($collection) && $collection !== '') {
            return $collection;
        }

        return (string) (config('ai-kit.mcp.default_collection')
            ?: config('ai-kit.default_collection', 'default'));
    }
}
