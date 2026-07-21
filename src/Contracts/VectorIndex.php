<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Contracts;

use SolutionForest\AiKit\Models\Chunk;

/**
 * A vector driver: both a Retriever (search) and a writable index (store/forget)
 * so ingestion and retrieval share one implementation per backend.
 */
interface VectorIndex extends Retriever
{
    /**
     * Persist $chunk's embedding into the search index. Assumes the chunk row
     * (with its `embedding` array) is already saved.
     */
    public function store(Chunk $chunk): void;

    /**
     * Remove a chunk from the search index.
     */
    public function forget(int $chunkId): void;

    /**
     * Remove all of a document's chunks from the search index.
     */
    public function forgetDocument(int $documentId): void;
}
