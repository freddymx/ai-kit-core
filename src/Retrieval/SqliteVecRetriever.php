<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Retrieval;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SQLiteConnection;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Data\RetrievalOptions;
use SolutionForest\AiKit\Models\Chunk;
use SolutionForest\AiKit\Support\Vector;
use Throwable;

/**
 * SQLite vector driver. Uses the sqlite-vec (vec1) loadable extension for KNN
 * when it is available, and otherwise falls back to a pure-PHP cosine scan over
 * the collection's chunks. The fallback needs zero infrastructure — ideal for
 * demos and CI — and is exact, just O(n) per query.
 */
final class SqliteVecRetriever extends AbstractRetriever
{
    private const VEC_TABLE = 'ai_kit_vec_chunks';

    /** Over-fetch multiplier so post-KNN collection filtering still yields topK. */
    private const OVERFETCH = 8;

    private ?bool $vecReady = null;

    public function __construct(
        Embedder $embedder,
        private readonly ConnectionInterface $connection,
    ) {
        parent::__construct($embedder);
    }

    /**
     * @return array<int, float>
     */
    protected function similaritySearch(array $queryVector, int $collectionId, RetrievalOptions $opts): array
    {
        if ($this->vecExtensionReady()) {
            $scores = $this->knnSearch($queryVector, $collectionId, $opts);

            // If the ANN index returned nothing (e.g. not yet populated), fall
            // back so freshly-seeded demos still retrieve.
            if ($scores !== []) {
                return $scores;
            }
        }

        return $this->scanSearch($queryVector, $collectionId, $opts);
    }

    /**
     * Exact pure-PHP cosine scan. Always correct; the default path.
     *
     * @return array<int, float>
     */
    private function scanSearch(array $queryVector, int $collectionId, RetrievalOptions $opts): array
    {
        $scores = [];

        $this->chunkQuery($collectionId, $opts)
            ->select(['id', 'embedding'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$scores, $queryVector): void {
                foreach ($rows as $row) {
                    $embedding = $this->decodeEmbedding($row->embedding);
                    if ($embedding === []) {
                        continue;
                    }
                    $scores[(int) $row->id] = Vector::cosine($queryVector, $embedding);
                }
            });

        return $scores;
    }

    /**
     * KNN via the vec1 virtual table, then filter to the collection/tenant.
     *
     * @return array<int, float>
     */
    private function knnSearch(array $queryVector, int $collectionId, RetrievalOptions $opts): array
    {
        $k = max($opts->topK * self::OVERFETCH, $opts->topK);
        $blob = Vector::toBlob($queryVector);
        $params = json_encode(['k' => $k, 'distance' => 'cos'], JSON_THROW_ON_ERROR);

        try {
            $rows = $this->connection->select(
                'SELECT chunk_id, vec1_cos_distance(?, vector) AS distance '
                . 'FROM ' . self::VEC_TABLE . '(?, ?) ORDER BY distance ASC',
                [$blob, $blob, $params],
            );
        } catch (Throwable) {
            // vec1 unavailable or table missing — signal caller to scan instead.
            $this->vecReady = false;

            return [];
        }

        if ($rows === []) {
            return [];
        }

        $candidateIds = array_map(static fn ($r): int => (int) $r->chunk_id, $rows);

        // Keep only chunks in this collection/tenant, mapping cosine distance
        // (0..2) back to similarity (1 - distance).
        $allowed = $this->chunkQuery($collectionId, $opts)
            ->whereIn('id', $candidateIds)
            ->pluck('id')
            ->flip();

        $scores = [];
        foreach ($rows as $row) {
            $id = (int) $row->chunk_id;
            if ($allowed->has($id)) {
                $scores[$id] = 1.0 - (float) $row->distance;
            }
        }

        return $scores;
    }

    public function store(Chunk $chunk): void
    {
        if (! $this->vecExtensionReady() || $chunk->embedding === null) {
            return;
        }

        $this->forget((int) $chunk->getKey());

        $this->connection->insert(
            'INSERT INTO ' . self::VEC_TABLE . '(rowid, chunk_id, vector) VALUES (?, ?, ?)',
            [(int) $chunk->getKey(), (int) $chunk->getKey(), Vector::toBlob($chunk->embedding)],
        );
    }

    public function forget(int $chunkId): void
    {
        if (! $this->vecExtensionReady()) {
            return;
        }

        try {
            $this->connection->delete('DELETE FROM ' . self::VEC_TABLE . ' WHERE rowid = ?', [$chunkId]);
        } catch (Throwable) {
            // Best-effort; the scan fallback does not depend on the vtable.
        }
    }

    public function forgetDocument(int $documentId): void
    {
        if (! $this->vecExtensionReady()) {
            return;
        }

        $ids = Chunk::query()->where('document_id', $documentId)->pluck('id');
        foreach ($ids as $id) {
            $this->forget((int) $id);
        }
    }

    /**
     * Load the extension (once) and ensure the virtual table exists. Any failure
     * disables the fast path permanently for this instance.
     */
    private function vecExtensionReady(): bool
    {
        if ($this->vecReady !== null) {
            return $this->vecReady;
        }

        $path = config('ai-kit.retrieval.sqlite.extension_path');

        if (! $path || ! $this->connection instanceof SQLiteConnection) {
            return $this->vecReady = false;
        }

        try {
            $pdo = $this->connection->getPdo();

            // PHP 8.3+: PDO\Sqlite::loadExtension().
            if (method_exists($pdo, 'loadExtension')) {
                $pdo->loadExtension($path);
            } else {
                return $this->vecReady = false;
            }

            $dimensions = (int) config('ai-kit.embeddings.dimensions', 1536);
            $this->connection->statement(
                'CREATE VIRTUAL TABLE IF NOT EXISTS ' . self::VEC_TABLE
                . '(vector float[' . $dimensions . '], chunk_id)'
            );

            return $this->vecReady = true;
        } catch (Throwable) {
            return $this->vecReady = false;
        }
    }

    /**
     * @return array<int, float>
     */
    private function decodeEmbedding(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map('floatval', $raw);
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_map('floatval', $decoded) : [];
        }

        return [];
    }
}
