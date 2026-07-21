<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SolutionForest\AiKit\Services\ChatService;

/**
 * MCP tool: a written, grounded answer with source citations.
 */
final class AskKnowledgeBaseTool extends Tool
{
    public function name(): string
    {
        return 'ask_knowledge_base';
    }

    public function description(): string
    {
        return 'Ask a question and get a written answer grounded ONLY in the knowledge '
            . 'base, with source document titles cited. Returns "not in the knowledge '
            . 'base" when unsupported.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->description('The question to answer.')->required(),
            'collection' => $schema->string()->description('Collection slug. Defaults to the configured default.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $result = app(ChatService::class)->ask(
            (string) $request->get('question'),
            McpCollection::resolve($request->get('collection')),
        );

        return Response::json([
            'answer' => $result->answer,
            'citations' => $result->citations,
        ]);
    }
}
