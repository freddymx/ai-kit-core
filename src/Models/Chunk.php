<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SolutionForest\AiKit\Models\Concerns\BelongsToTenant;

/**
 * A chunk of a document plus its embedding. The `embedding` column (JSON array)
 * is the portable source of truth; each vector driver mirrors it into its own
 * search index (sqlite-vec virtual table / pgvector column).
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int $collection_id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content
 * @property array<int, float>|null $embedding
 * @property string|null $embedding_model
 * @property int|null $token_count
 */
final class Chunk extends AiKitModel
{
    use BelongsToTenant;

    protected string $baseTable = 'chunks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'chunk_index' => 'integer',
            'token_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
