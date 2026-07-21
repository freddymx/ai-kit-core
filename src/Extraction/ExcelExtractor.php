<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Extraction;

use RuntimeException;
use SolutionForest\AiKit\Contracts\TextExtractor;
use ZipArchive;

/**
 * Extracts text from modern Excel workbooks (.xlsx) with no external
 * dependency — an .xlsx is a ZIP of XML. We pull the shared string table and
 * any inline strings, which holds the workbook's textual content (labels,
 * headers, notes). Legacy .xls (binary) is not supported.
 */
final class ExcelExtractor implements TextExtractor
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function supports(string $mime): bool
    {
        return $mime === self::MIME;
    }

    public function extract(string $path, string $mime): string
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open Excel workbook [{$path}].");
        }

        $parts = [];

        // Shared strings hold most textual cell content.
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($shared) && $shared !== '') {
            $parts[] = $this->textNodes($shared);
        }

        // Inline strings (cells stored as t="inlineStr") live in the sheets.
        for ($i = 1; $i <= 50; $i++) {
            $sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
            if ($sheet === false) {
                if ($i === 1) {
                    continue;
                }
                break;
            }
            if (str_contains($sheet, '<is>')) {
                $parts[] = $this->textNodes($sheet);
            }
        }

        $zip->close();

        $text = trim(implode("\n", array_filter($parts)));

        if ($text === '') {
            throw new RuntimeException("No extractable text found in Excel workbook [{$path}].");
        }

        return $text;
    }

    /**
     * Pull the inner text of every <t> element and join with newlines.
     */
    private function textNodes(string $xml): string
    {
        if (! preg_match_all('#<t[^>]*>(.*?)</t>#s', $xml, $matches)) {
            return '';
        }

        $lines = array_map(
            static fn (string $s): string => trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_XML1, 'UTF-8')),
            $matches[1],
        );

        return implode("\n", array_filter($lines, static fn (string $s): bool => $s !== ''));
    }
}
