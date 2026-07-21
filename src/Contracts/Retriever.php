<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Contracts;

use SolutionForest\AiKit\Data\ChunkCollection;
use SolutionForest\AiKit\Data\RetrievalOptions;

interface Retriever
{
    /**
     * Return the most similar chunks to $query, honouring top-k, collection,
     * min-score and tenant scoping from $opts.
     */
    public function search(string $query, RetrievalOptions $opts): ChunkCollection;
}
