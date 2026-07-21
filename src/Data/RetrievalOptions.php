<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Data;

/**
 * Options controlling a similarity search: how many chunks, which collection,
 * the minimum cosine similarity to keep, and an optional tenant scope.
 */
final readonly class RetrievalOptions
{
    public function __construct(
        public int $topK = 5,
        public ?string $collection = null,
        public float $minScore = 0.2,
        public ?string $tenantId = null,
    ) {
    }

    public static function fromConfig(?string $collection = null): self
    {
        return new self(
            topK: (int) config('ai-kit.retrieval.top_k', 5),
            collection: $collection ?? config('ai-kit.default_collection', 'default'),
            minScore: (float) config('ai-kit.retrieval.min_score', 0.2),
        );
    }

    public function withCollection(string $collection): self
    {
        return new self($this->topK, $collection, $this->minScore, $this->tenantId);
    }

    public function withTenant(?string $tenantId): self
    {
        return new self($this->topK, $this->collection, $this->minScore, $tenantId);
    }

    public function withTopK(int $topK): self
    {
        return new self($topK, $this->collection, $this->minScore, $this->tenantId);
    }
}
