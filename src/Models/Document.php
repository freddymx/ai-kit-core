<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SolutionForest\AiKit\Enums\DocumentStatus;
use SolutionForest\AiKit\Models\Concerns\BelongsToTenant;

/**
 * A source document uploaded into a collection and ingested into chunks.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int $collection_id
 * @property string $title
 * @property string|null $source_path
 * @property string|null $mime
 * @property DocumentStatus $status
 * @property int $chunk_count
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $meta
 */
final class Document extends AiKitModel
{
    use BelongsToTenant;

    protected string $baseTable = 'documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'chunk_count' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function markProcessing(): void
    {
        $this->update(['status' => DocumentStatus::Processing, 'failure_reason' => null]);
    }

    public function markIndexed(int $chunkCount): void
    {
        $this->update([
            'status' => DocumentStatus::Indexed,
            'chunk_count' => $chunkCount,
            'failure_reason' => null,
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => DocumentStatus::Failed,
            'failure_reason' => mb_substr($reason, 0, 1000),
        ]);
    }
}
