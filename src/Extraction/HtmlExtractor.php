<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Extraction;

use RuntimeException;
use SolutionForest\AiKit\Contracts\TextExtractor;

/**
 * Strips HTML to readable text: drops script/style, converts block elements to
 * line breaks, and decodes entities.
 */
final class HtmlExtractor implements TextExtractor
{
    private const MIMES = ['text/html', 'application/xhtml+xml'];

    public function supports(string $mime): bool
    {
        return in_array($mime, self::MIMES, true);
    }

    public function extract(string $path, string $mime): string
    {
        $html = @file_get_contents($path);

        if ($html === false) {
            throw new RuntimeException("Unable to read HTML file at [{$path}].");
        }

        // Remove non-content elements entirely.
        $html = (string) preg_replace('#<(script|style|head|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);

        // Turn block-level boundaries into newlines so text doesn't run together.
        $html = (string) preg_replace('#</(p|div|li|h[1-6]|tr|section|article|header|footer|br)\s*>#i', "\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse runs of blank lines / spaces.
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);

        return trim($text);
    }
}
