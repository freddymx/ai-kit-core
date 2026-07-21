<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Data\RetrievalOptions;
use Stringable;

/**
 * Laravel AI SDK tool wrapping the Retriever. The agent calls it to search the
 * knowledge base; results carry source metadata (document_title, document_id,
 * chunk_index, score) so the model can cite and the UI can render source links.
 */
final class SearchKnowledgeBase implements Tool
{
    public function __construct(
        private readonly ?string $collection = null,
        private readonly ?string $tenantId = null,
    ) {
    }

    public function description(): Stringable|string
    {
        return 'Search the knowledge base for passages relevant to a question. '
            . 'Always call this before answering domain questions. Returns passages '
            . 'with their source document title and a relevance score; cite sources '
            . 'by document title.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query — a natural-language question or keywords.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        /** @var Retriever $retriever */
        $retriever = app(Retriever::class);

        $opts = RetrievalOptions::fromConfig($this->collection)
            ->withTenant($this->tenantId ?? app(\SolutionForest\AiKit\AiKit::class)->tenant());

        $results = $retriever->search((string) $request['query'], $opts);

        if ($results->isEmpty()) {
            return json_encode([
                'results' => [],
                'note' => 'No relevant passages found in the knowledge base.',
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'results' => $results->map(fn ($chunk) => [
                'document_title' => $chunk->documentTitle,
                'document_id' => $chunk->documentId,
                'chunk_index' => $chunk->chunkIndex,
                'score' => round($chunk->score, 4),
                'content' => $chunk->content,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR);
    }
}
