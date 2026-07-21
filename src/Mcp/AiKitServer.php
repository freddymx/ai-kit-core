<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Mcp;

use Laravel\Mcp\Server;
use SolutionForest\AiKit\Mcp\Tools\AskKnowledgeBaseTool;
use SolutionForest\AiKit\Mcp\Tools\IngestUrlTool;
use SolutionForest\AiKit\Mcp\Tools\ListCollectionsTool;
use SolutionForest\AiKit\Mcp\Tools\SearchKnowledgeBaseTool;

/**
 * ai-kit as a Model Context Protocol server, so MCP clients (Claude Desktop,
 * IDE agents) can search and chat over your knowledge bases.
 *
 * Requires laravel/mcp. Registered by AiKitServiceProvider only when
 * config('ai-kit.features.mcp') is enabled. Serve stdio:
 *
 *   php artisan mcp:start ai-kit
 */
final class AiKitServer extends Server
{
    protected string $name = 'ai-kit';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
    Retrieval-augmented search and Q&A over the host application's ai-kit
    knowledge bases (collections). Use `search_knowledge_base` for raw passages,
    `ask_knowledge_base` for a written, cited answer, `list_collections` to
    discover available knowledge bases, and `ingest_url` to add a web page.
    Answers are grounded only in the indexed documents.
    MARKDOWN;

    /** @var array<int, class-string> */
    protected array $tools = [
        SearchKnowledgeBaseTool::class,
        AskKnowledgeBaseTool::class,
        ListCollectionsTool::class,
        IngestUrlTool::class,
    ];
}
