<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Data;

/**
 * A single piece of text produced by a Chunker, before embedding.
 */
final readonly class TextChunk
{
    public function __construct(
        public string $content,
        public int $index,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
    }
}
