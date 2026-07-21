<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Retrieval;

use Illuminate\Database\ConnectionInterface;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Data\RetrievalOptions;
use SolutionForest\AiKit\Models\Chunk;
use SolutionForest\AiKit\Support\Vector;

/**
 * PostgreSQL + pgvector driver. Cosine similarity via the `<=>` operator against
 * the `embedding_vector` column (added by the pgvector migration and indexed
 * with HNSW / IVFFlat).
 */
final class PgVectorRetriever extends AbstractRetriever
{
    public function __construct(
        Embedder $embedder,
        private readonly ConnectionInterface $connection,
    ) {
        parent::__construct($embedder);
    }

    private function table(): string
    {
        return (string) config('ai-kit.database.prefix', 'ai_kit_') . 'chunks';
    }

    /**
     * @return array<int, float>
     */
    protected function similaritySearch(array $queryVector, int $collectionId, RetrievalOptions $opts): array
    {
        $literal = Vector::toPgLiteral($queryVector);
        $table = $this->table();
        // Over-fetch so the min-score filter applied during hydration still
        // leaves enough rows to satisfy topK.
        $limit = max($opts->topK * 4, $opts->topK);

        $bindings = [$literal, $collectionId];
        $tenantClause = '';

        if ($opts->tenantId !== null) {
            $tenantClause = ' AND tenant_id = ?';
            $bindings[] = $opts->tenantId;
        }

        $bindings[] = $literal;
        $bindings[] = $limit;

        $sql = "SELECT id, 1 - (embedding_vector <=> ?::vector) AS score "
            . "FROM {$table} "
            . "WHERE collection_id = ?{$tenantClause} AND embedding_vector IS NOT NULL "
            . "ORDER BY embedding_vector <=> ?::vector ASC "
            . 'LIMIT ?';

        $rows = $this->connection->select($sql, $bindings);

        $scores = [];
        foreach ($rows as $row) {
            $scores[(int) $row->id] = (float) $row->score;
        }

        return $scores;
    }

    public function store(Chunk $chunk): void
    {
        if ($chunk->embedding === null) {
            return;
        }

        $this->connection->update(
            "UPDATE {$this->table()} SET embedding_vector = ?::vector WHERE id = ?",
            [Vector::toPgLiteral($chunk->embedding), (int) $chunk->getKey()],
        );
    }

    public function forget(int $chunkId): void
    {
        // The vector lives on the chunk row; deleting the row removes it.
    }

    public function forgetDocument(int $documentId): void
    {
        // Cascade delete on chunks removes the vectors.
    }
}
