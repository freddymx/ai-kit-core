<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Extraction;

use RuntimeException;
use SolutionForest\AiKit\Contracts\TextExtractor;

/**
 * Handles plain text and markdown. Markdown is read as-is — it chunks and
 * embeds well without stripping, and headings aid retrieval.
 */
final class PlainTextExtractor implements TextExtractor
{
    private const MIMES = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
    ];

    public function supports(string $mime): bool
    {
        if ($mime === 'text/html' || $mime === 'application/xhtml+xml') {
            return false; // handled by HtmlExtractor
        }

        return in_array($mime, self::MIMES, true) || str_starts_with($mime, 'text/');
    }

    public function extract(string $path, string $mime): string
    {
        if ($path === '') {
            throw new RuntimeException('No source path provided for text extraction.');
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read text file at [{$path}].");
        }

        return $contents;
    }
}
