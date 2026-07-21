<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Chunking;

use SolutionForest\AiKit\Contracts\Chunker;
use SolutionForest\AiKit\Data\ChunkOptions;
use SolutionForest\AiKit\Data\TextChunk;

/**
 * A recursive, sentence-aware character chunker.
 *
 * Splits text on progressively finer separators (paragraph → line → sentence →
 * word) so chunks land on natural boundaries where possible, then packs them up
 * to `size` characters with `overlap` characters carried between neighbours.
 */
final class RecursiveTextChunker implements Chunker
{
    /** Separators from coarsest to finest. */
    private const SEPARATORS = ["\n\n", "\n", '. ', '? ', '! ', '; ', ' ', ''];

    /**
     * @return array<int, TextChunk>
     */
    public function chunk(string $text, ChunkOptions $opts): array
    {
        $text = trim($this->normalise($text));

        if ($text === '') {
            return [];
        }

        $size = max(1, $opts->size);
        $overlap = max(0, min($opts->overlap, $size - 1));

        $pieces = $this->split($text, $size, self::SEPARATORS);
        $merged = $this->mergeWithOverlap($pieces, $size, $overlap);

        $chunks = [];
        foreach ($merged as $i => $content) {
            $chunks[] = new TextChunk(content: $content, index: $i);
        }

        return $chunks;
    }

    private function normalise(string $text): string
    {
        // Normalise line endings and strip trailing whitespace per line.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return (string) preg_replace('/[ \t]+\n/', "\n", $text);
    }

    /**
     * Recursively split $text into pieces no larger than $size using the first
     * separator that yields sub-$size fragments; fall back to hard slicing.
     *
     * @param  list<string>  $separators
     * @return list<string>
     */
    private function split(string $text, int $size, array $separators): array
    {
        if ($this->length($text) <= $size) {
            return $text === '' ? [] : [$text];
        }

        $separator = $separators[0] ?? '';
        $rest = array_slice($separators, 1);

        if ($separator === '') {
            return $this->hardSlice($text, $size);
        }

        $parts = explode($separator, $text);

        // This separator does not divide the text; try a finer one.
        if (count($parts) === 1) {
            return $this->split($text, $size, $rest);
        }

        $out = [];
        foreach ($parts as $i => $part) {
            // Re-attach the separator (except after the last part) to preserve text.
            $piece = $part . ($i < count($parts) - 1 ? $separator : '');

            if ($piece === '') {
                continue;
            }

            if ($this->length($piece) > $size) {
                array_push($out, ...$this->split($piece, $size, $rest));
            } else {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * Greedily pack fine-grained pieces up to $size, carrying $overlap chars of
     * trailing context from each emitted chunk into the next.
     *
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private function mergeWithOverlap(array $pieces, int $size, int $overlap): array
    {
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current . $piece;

            if ($this->length($candidate) <= $size) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = trim($current);
                $tail = $overlap > 0 ? $this->tail($current, $overlap) : '';
                $current = $tail . $piece;
            } else {
                // Single piece already exceeds size (shouldn't happen post-split).
                $chunks[] = trim($piece);
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn (string $c): bool => $c !== ''));
    }

    private function tail(string $text, int $chars): string
    {
        return function_exists('mb_substr')
            ? mb_substr($text, -$chars)
            : substr($text, -$chars);
    }

    /**
     * @return list<string>
     */
    private function hardSlice(string $text, int $size): array
    {
        $out = [];
        $length = $this->length($text);

        for ($offset = 0; $offset < $length; $offset += $size) {
            $out[] = mb_substr($text, $offset, $size);
        }

        return $out;
    }

    private function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}
