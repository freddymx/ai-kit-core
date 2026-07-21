<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Extraction;

use RuntimeException;
use Smalot\PdfParser\Parser;
use SolutionForest\AiKit\Contracts\TextExtractor;
use Throwable;

/**
 * Extracts text from PDFs via smalot/pdfparser.
 */
final class PdfExtractor implements TextExtractor
{
    public function __construct(private readonly Parser $parser = new Parser())
    {
    }

    public function supports(string $mime): bool
    {
        return $mime === 'application/pdf';
    }

    public function extract(string $path, string $mime): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Unable to read PDF file at [{$path}].");
        }

        try {
            $text = $this->parser->parseFile($path)->getText();
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to parse PDF [{$path}]: {$e->getMessage()}", previous: $e);
        }

        // pdfparser inserts null bytes and stray whitespace on some documents.
        $text = str_replace("\0", '', $text);

        return trim((string) preg_replace('/[ \t]+/', ' ', $text));
    }
}
