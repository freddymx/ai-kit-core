<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Services;

use Illuminate\Support\Collection as Support;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Models\Document;

/**
 * Document operations (list, chunks, re-index, delete, ingest) shared by every
 * surface. Ingestion itself is delegated to IngestionService.
 */
final class DocumentService
{
    public function __construct(private readonly IngestionService $ingestion)
    {
    }

    /**
     * @return Support<int, Document>
     */
    public function forCollection(Collection $collection): Support
    {
        return $collection->documents()->latest()->get();
    }

    public function find(int $id): ?Document
    {
        return Document::query()->find($id);
    }

    /**
     * @return Support<int, \SolutionForest\AiKit\Models\Chunk>
     */
    public function chunks(Document $document): Support
    {
        return $document->chunks()->orderBy('chunk_index')->get();
    }

    public function ingestPath(Collection $collection, string $path, ?string $title = null, bool $sync = false): Document
    {
        return $this->ingestion->createFromPath($collection, $path, $title, $sync);
    }

    public function ingestUrl(Collection $collection, string $url, ?string $title = null, bool $sync = false): Document
    {
        return $this->ingestion->createFromUrl($collection, $url, $title, $sync);
    }

    public function reindex(Document $document, bool $sync = false): void
    {
        $sync ? $this->ingestion->sync($document) : $this->ingestion->queue($document);
    }

    public function delete(Document $document): bool
    {
        return (bool) $document->delete();
    }
}
