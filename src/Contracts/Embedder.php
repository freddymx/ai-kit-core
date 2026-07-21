<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Contracts;

interface Embedder
{
    /**
     * Embed one or more texts. Returns a list of float vectors, one per input,
     * in the same order.
     *
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     */
    public function embed(array $texts): array;

    /**
     * Embed a single text and return its vector.
     *
     * @return array<int, float>
     */
    public function embedOne(string $text): array;

    /**
     * The model name used, stored on chunks so re-indexing with a different
     * model is detectable.
     */
    public function model(): string;
}
