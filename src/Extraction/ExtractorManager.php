<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Extraction;

use RuntimeException;
use SolutionForest\AiKit\Contracts\TextExtractor;

/**
 * Routes a file to the first registered extractor that supports its mime type.
 */
final class ExtractorManager implements TextExtractor
{
    /** @var list<TextExtractor> */
    private array $extractors;

    public function __construct(TextExtractor ...$extractors)
    {
        $this->extractors = array_values($extractors);
    }

    public function register(TextExtractor $extractor): self
    {
        $this->extractors[] = $extractor;

        return $this;
    }

    public function supports(string $mime): bool
    {
        return $this->resolve($mime) !== null;
    }

    public function extract(string $path, string $mime): string
    {
        $extractor = $this->resolve($mime)
            ?? throw new RuntimeException("No text extractor registered for mime type [{$mime}].");

        return $extractor->extract($path, $mime);
    }

    private function resolve(string $mime): ?TextExtractor
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mime)) {
                return $extractor;
            }
        }

        return null;
    }
}
