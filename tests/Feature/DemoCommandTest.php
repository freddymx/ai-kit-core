<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SolutionForest\AiKit\Enums\DocumentStatus;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Models\Document;

uses(RefreshDatabase::class);

it('seeds and synchronously ingests the demo knowledge base so the playground works immediately', function (): void {
    $this->artisan('ai-kit:demo')->assertSuccessful();

    $collection = Collection::where('slug', 'demo')->first();

    expect($collection)->not->toBeNull()
        ->and($collection->documents()->count())->toBe(3)
        ->and(Document::where('status', DocumentStatus::Indexed)->count())->toBe(3);

    // Chunks were embedded, so the demo is immediately queryable.
    expect($collection->chunks()->whereNotNull('embedding')->count())->toBeGreaterThan(0);
});

it('ingests a file via the ai-kit:ingest command', function (): void {
    $path = writeTempDoc('cli.md', str_repeat('CLI ingested knowledge about pricing tiers. ', 20));

    $this->artisan('ai-kit:ingest', ['path' => $path, '--collection' => 'cli-kb'])
        ->assertSuccessful();

    expect(Collection::where('slug', 'cli-kb')->first()?->documents()->count())->toBe(1);
});
