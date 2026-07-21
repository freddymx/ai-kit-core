<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Contracts;

use SolutionForest\AiKit\Data\ChunkOptions;
use SolutionForest\AiKit\Data\TextChunk;

interface Chunker
{
    /**
     * Split $text into overlapping chunks.
     *
     * @return array<int, TextChunk>
     */
    public function chunk(string $text, ChunkOptions $opts): array;
}
