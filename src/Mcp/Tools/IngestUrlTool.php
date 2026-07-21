<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SolutionForest\AiKit\Services\CollectionService;
use SolutionForest\AiKit\Services\DocumentService;

/**
 * MCP tool: fetch a web page and ingest it into a knowledge base.
 */
final class IngestUrlTool extends Tool
{
    public function name(): string
    {
        return 'ingest_url';
    }

    public function description(): string
    {
        return 'Fetch a web page and add it to a knowledge base so it becomes '
            . 'searchable. The collection is created if it does not exist.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The page URL to ingest.')->required(),
            'collection' => $schema->string()->description('Collection slug. Created if missing.'),
            'title' => $schema->string()->description('Optional document title.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $collections = app(CollectionService::class);
        $slug = McpCollection::resolve($request->get('collection'));
        $collection = $collections->find($slug) ?? $collections->firstOrCreate($slug);

        $document = app(DocumentService::class)->ingestUrl(
            $collection,
            (string) $request->get('url'),
            $request->get('title') ? (string) $request->get('title') : null,
            sync: false,
        );

        return Response::json([
            'status' => 'queued',
            'document_id' => $document->id,
            'title' => $document->title,
            'collection' => $slug,
        ]);
    }
}
