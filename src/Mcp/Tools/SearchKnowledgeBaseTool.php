<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Data\RetrievalOptions;

/**
 * MCP tool: raw vector retrieval (no LLM) — returns matching passages + scores.
 */
final class SearchKnowledgeBaseTool extends Tool
{
    public function name(): string
    {
        return 'search_knowledge_base';
    }

    public function description(): string
    {
        return 'Search a knowledge base and return the most relevant passages with '
            . 'their source document title, chunk index, and relevance score.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Natural-language search query.')->required(),
            'collection' => $schema->string()->description('Collection slug. Defaults to the configured default.'),
            'top' => $schema->integer()->description('Max results (default from config).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $collection = McpCollection::resolve($request->get('collection'));
        $opts = RetrievalOptions::fromConfig($collection);

        if ($request->get('top')) {
            $opts = $opts->withTopK((int) $request->get('top'));
        }

        $results = app(Retriever::class)->search((string) $request->get('query'), $opts);

        return Response::json([
            'collection' => $collection,
            'results' => $results->map(fn ($c) => $c->toArray())->values()->all(),
        ]);
    }
}
