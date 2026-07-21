<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Services\CollectionService;

/**
 * MCP tool: list the available knowledge bases + their stats.
 */
final class ListCollectionsTool extends Tool
{
    public function name(): string
    {
        return 'list_collections';
    }

    public function description(): string
    {
        return 'List the available knowledge bases (collections), with document and '
            . 'chunk counts, so you can pick one to search or ask.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $collections = app(CollectionService::class)->all()->map(fn (Collection $c) => [
            'slug' => $c->slug,
            'name' => $c->name,
            'description' => $c->description,
            'documents' => $c->documents_count,
            'chunks' => $c->chunks_count,
        ])->values()->all();

        return Response::json(['collections' => $collections]);
    }
}
