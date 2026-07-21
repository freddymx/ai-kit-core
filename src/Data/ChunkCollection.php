<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Data;

use Illuminate\Support\Collection;

/**
 * A typed collection of RetrievedChunk results, ordered by descending score.
 *
 * @extends Collection<int, RetrievedChunk>
 */
final class ChunkCollection extends Collection
{
    /**
     * Render the retrieved chunks as a context block for inject-mode RAG.
     */
    public function toContextString(): string
    {
        return $this
            ->map(fn (RetrievedChunk $c): string => sprintf(
                "[Source: %s (#%d)]\n%s",
                $c->documentTitle,
                $c->chunkIndex,
                $c->content,
            ))
            ->implode("\n\n---\n\n");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toCitations(): array
    {
        return $this->map(fn (RetrievedChunk $c): array => $c->toCitation())->values()->all();
    }
}
