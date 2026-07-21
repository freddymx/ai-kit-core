<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Services;

use Illuminate\Support\Collection as Support;
use SolutionForest\AiKit\AiKit;
use SolutionForest\AiKit\Models\Chunk;
use SolutionForest\AiKit\Models\Collection;

/**
 * Knowledge-base (collection) operations shared by every surface.
 */
final class CollectionService
{
    /**
     * @return Support<int, Collection>
     */
    public function all(): Support
    {
        return Collection::query()->withCount(['documents', 'chunks'])->orderBy('name')->get();
    }

    public function find(string $slug): ?Collection
    {
        return Collection::query()->where('slug', $slug)->first();
    }

    public function create(string $name, ?string $slug = null, ?string $description = null): Collection
    {
        return Collection::create(array_filter([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'tenant_id' => app(AiKit::class)->tenant(),
        ], fn ($v) => $v !== null));
    }

    public function firstOrCreate(string $slug, ?string $name = null): Collection
    {
        return Collection::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name ?? str($slug)->headline()->toString()],
        );
    }

    public function delete(Collection|string $collection): bool
    {
        $collection = $collection instanceof Collection ? $collection : $this->find($collection);

        return (bool) $collection?->delete();
    }

    /**
     * @return array{documents:int, indexed:int, chunks:int, embedded_chunks:int}
     */
    public function stats(Collection $collection): array
    {
        return [
            'documents' => $collection->documents()->count(),
            'indexed' => $collection->documents()->where('status', 'indexed')->count(),
            'chunks' => Chunk::query()->where('collection_id', $collection->getKey())->count(),
            'embedded_chunks' => Chunk::query()->where('collection_id', $collection->getKey())->whereNotNull('embedding')->count(),
        ];
    }
}
