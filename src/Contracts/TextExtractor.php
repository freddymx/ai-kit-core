<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Contracts;

interface TextExtractor
{
    /**
     * Extract plain text from the file at $path with the given $mime type.
     */
    public function extract(string $path, string $mime): string;

    /**
     * Whether this extractor can handle the given mime type.
     */
    public function supports(string $mime): bool;
}
