<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Extraction;

use RuntimeException;
use SolutionForest\AiKit\Contracts\TextExtractor;
use ZipArchive;

/**
 * Extracts text from modern Word documents (.docx) with no external
 * dependency — a .docx is a ZIP of XML, so we read word/document.xml and pull
 * out the text runs. Legacy .doc (binary) is not supported.
 */
final class WordExtractor implements TextExtractor
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function supports(string $mime): bool
    {
        return $mime === self::MIME;
    }

    public function extract(string $path, string $mime): string
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open Word document [{$path}].");
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException("Word document [{$path}] is missing word/document.xml.");
        }

        // Paragraphs and line breaks become newlines; tabs become tabs.
        $xml = (string) preg_replace('#</w:p>#', "\n", $xml);
        $xml = (string) preg_replace('#<w:tab\b[^>]*/?>#', "\t", $xml);
        $xml = (string) preg_replace('#<w:br\b[^>]*/?>#', "\n", $xml);

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Collapse excessive whitespace produced by the XML structure.
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);

        return trim($text);
    }
}
