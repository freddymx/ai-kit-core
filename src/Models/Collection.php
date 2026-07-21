<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use SolutionForest\AiKit\Models\Concerns\BelongsToTenant;

/**
 * A named knowledge base. Documents and their chunks belong to a collection.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
final class Collection extends AiKitModel
{
    use BelongsToTenant;

    protected string $baseTable = 'collections';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Collection $collection): void {
            if (blank($collection->slug)) {
                $collection->slug = Str::slug($collection->name);
            }
        });
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
