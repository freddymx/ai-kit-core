<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Data;

/**
 * Options controlling how a document's text is split into chunks.
 */
final readonly class ChunkOptions
{
    public function __construct(
        public int $size = 1000,
        public int $overlap = 200,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            size: (int) config('ai-kit.chunking.size', 1000),
            overlap: (int) config('ai-kit.chunking.overlap', 200),
        );
    }

    public static function make(?int $size = null, ?int $overlap = null): self
    {
        $base = self::fromConfig();

        return new self(
            size: $size ?? $base->size,
            overlap: $overlap ?? $base->overlap,
        );
    }
}
