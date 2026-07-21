<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Data;

use SolutionForest\AiKit\Models\Chunk;

/**
 * A chunk returned from retrieval, carrying its similarity score and the
 * source metadata the agent and UI need to render citations.
 */
final readonly class RetrievedChunk
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentTitle,
        public int $chunkIndex,
        public string $content,
        public float $score,
    ) {
    }

    public static function fromModel(Chunk $chunk, float $score): self
    {
        return new self(
            chunkId: (int) $chunk->getKey(),
            documentId: (int) $chunk->document_id,
            documentTitle: (string) ($chunk->document?->title ?? 'Untitled'),
            chunkIndex: (int) $chunk->chunk_index,
            content: (string) $chunk->content,
            score: $score,
        );
    }

    /**
     * The citation payload exposed to the agent (via the tool) and the UI.
     *
     * @return array<string, mixed>
     */
    public function toCitation(): array
    {
        return [
            'document_id' => $this->documentId,
            'document_title' => $this->documentTitle,
            'chunk_index' => $this->chunkIndex,
            'score' => round($this->score, 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->toCitation(),
            'chunk_id' => $this->chunkId,
            'content' => $this->content,
        ];
    }
}
