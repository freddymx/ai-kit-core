<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request as McpRequest;
use SolutionForest\AiKit\Agents\RagAgent;
use SolutionForest\AiKit\Mcp\AiKitServer;
use SolutionForest\AiKit\Mcp\Tools\AskKnowledgeBaseTool;
use SolutionForest\AiKit\Mcp\Tools\ListCollectionsTool;
use SolutionForest\AiKit\Mcp\Tools\SearchKnowledgeBaseTool;

uses(RefreshDatabase::class);

it('registers the ai-kit tools on the MCP server', function (): void {
    $tools = (new ReflectionClass(AiKitServer::class))->getDefaultProperties()['tools'];

    expect($tools)
        ->toContain(SearchKnowledgeBaseTool::class, AskKnowledgeBaseTool::class, ListCollectionsTool::class);
});

it('exposes a query parameter in the search tool schema', function (): void {
    expect((new SearchKnowledgeBaseTool())->schema(new JsonSchemaTypeFactory()))->toHaveKey('query');
});

it('search tool returns matching passages', function (): void {
    seedCollection('kb');

    $content = (string) (new SearchKnowledgeBaseTool())
        ->handle(new McpRequest(['query' => 'refunds', 'collection' => 'kb']))
        ->content();

    $payload = json_decode($content, true);
    expect($payload['collection'])->toBe('kb')
        ->and($payload['results'])->not->toBeEmpty();
});

it('list_collections tool returns the collections', function (): void {
    seedCollection('kb');

    $content = (string) (new ListCollectionsTool())->handle(new McpRequest([]))->content();

    expect($content)->toContain('kb');
});

it('ask tool returns a grounded answer', function (): void {
    seedCollection('kb');
    RagAgent::fake(['Refunds take fourteen days.']);

    $content = (string) (new AskKnowledgeBaseTool())
        ->handle(new McpRequest(['question' => 'refund window?', 'collection' => 'kb']))
        ->content();

    expect($content)->toContain('fourteen days');
});
