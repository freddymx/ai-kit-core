<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Support;

use InvalidArgumentException;

/**
 * Vector math + serialization helpers shared by the vector drivers.
 */
final class Vector
{
    /**
     * Cosine similarity in [-1, 1]. Returns 0.0 for a zero-magnitude vector.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new InvalidArgumentException('Vectors must have the same dimensions to compare.');
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $va) {
            $vb = $b[$i];
            $dot += $va * $vb;
            $magA += $va * $va;
            $magB += $vb * $vb;
        }

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Pack a float vector into a little-endian float32 blob (sqlite-vec format).
     *
     * @param  array<int, float>  $vector
     */
    public static function toBlob(array $vector): string
    {
        return pack('g*', ...array_map('floatval', $vector));
    }

    /**
     * Render a vector as a pgvector text literal: "[0.1,0.2,...]".
     *
     * @param  array<int, float>  $vector
     */
    public static function toPgLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn (float $v): string => rtrim(sprintf('%.8F', $v), '0'), $vector)) . ']';
    }
}
