<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Retrieval;

use Illuminate\Database\Eloquent\Builder;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Contracts\VectorIndex;
use SolutionForest\AiKit\Data\ChunkCollection;
use SolutionForest\AiKit\Data\RetrievalOptions;
use SolutionForest\AiKit\Data\RetrievedChunk;
use SolutionForest\AiKit\Models\Chunk;
use SolutionForest\AiKit\Models\Collection;

/**
 * Shared retrieval flow: embed the query, resolve the collection, delegate the
 * similarity search to the concrete driver, then hydrate, score-filter and
 * order the results.
 */
abstract class AbstractRetriever implements VectorIndex
{
    public function __construct(protected readonly Embedder $embedder)
    {
    }

    public function search(string $query, RetrievalOptions $opts): ChunkCollection
    {
        $query = trim($query);

        if ($query === '') {
            return new ChunkCollection();
        }

        $collectionId = $this->resolveCollectionId($opts);

        if ($collectionId === null) {
            return new ChunkCollection();
        }

        $queryVector = $this->embedder->embedOne($query);

        if ($queryVector === []) {
            return new ChunkCollection();
        }

        // Driver returns [chunkId => score], score is cosine similarity in [0,1].
        $scores = $this->similaritySearch($queryVector, $collectionId, $opts);

        return $this->hydrate($scores, $opts);
    }

    /**
     * @return array<int, float>  chunkId => similarity score
     */
    abstract protected function similaritySearch(array $queryVector, int $collectionId, RetrievalOptions $opts): array;

    /**
     * Resolve the collection slug in $opts to an id within the tenant scope.
     */
    protected function resolveCollectionId(RetrievalOptions $opts): ?int
    {
        $slug = $opts->collection ?? config('ai-kit.default_collection', 'default');

        $collection = Collection::query()
            ->when($opts->tenantId !== null, fn (Builder $q) => $q->where('tenant_id', $opts->tenantId))
            ->where('slug', $slug)
            ->first();

        return $collection?->getKey();
    }

    /**
     * @param  array<int, float>  $scores  chunkId => similarity
     */
    protected function hydrate(array $scores, RetrievalOptions $opts): ChunkCollection
    {
        $scores = array_filter($scores, fn (float $s): bool => $s >= $opts->minScore);

        if ($scores === []) {
            return new ChunkCollection();
        }

        arsort($scores);
        $scores = array_slice($scores, 0, $opts->topK, true);

        $chunks = Chunk::query()
            ->with('document:id,title')
            ->whereIn('id', array_keys($scores))
            ->get()
            ->keyBy('id');

        $results = [];
        foreach ($scores as $chunkId => $score) {
            $chunk = $chunks->get($chunkId);
            if ($chunk instanceof Chunk) {
                $results[] = RetrievedChunk::fromModel($chunk, $score);
            }
        }

        return new ChunkCollection($results);
    }

    /**
     * Base query for a collection's chunks, tenant-scoped when requested.
     */
    protected function chunkQuery(int $collectionId, RetrievalOptions $opts): Builder
    {
        return Chunk::query()
            ->where('collection_id', $collectionId)
            ->when($opts->tenantId !== null, fn (Builder $q) => $q->where('tenant_id', $opts->tenantId))
            ->whereNotNull('embedding');
    }
}
