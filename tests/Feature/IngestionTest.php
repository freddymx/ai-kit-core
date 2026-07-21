<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use SolutionForest\AiKit\Enums\DocumentStatus;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Ingestion\Jobs\ChunkDocument;
use SolutionForest\AiKit\Ingestion\Jobs\EmbedChunks;
use SolutionForest\AiKit\Ingestion\Jobs\ExtractText;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Models\Document;

uses(RefreshDatabase::class);

it('ingests a document synchronously: chunks, embeds, and marks it indexed', function (): void {
    $collection = Collection::create(['name' => 'Handbook']);
    $path = writeTempDoc('policy.md', str_repeat('Refunds are processed within 14 days. ', 40));

    $document = app(IngestionService::class)->createFromPath($collection, $path, title: 'Refund Policy', sync: true);

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Indexed)
        ->and($document->chunk_count)->toBeGreaterThan(0)
        ->and($document->chunks()->count())->toBe($document->chunk_count);

    // Every chunk got an embedding and recorded its model.
    $document->chunks->each(function ($chunk): void {
        expect($chunk->embedding)->toBeArray()->not->toBeEmpty()
            ->and($chunk->embedding_model)->toBe('fake-hash-256');
    });
});

it('re-ingesting replaces old chunks rather than duplicating', function (): void {
    $collection = Collection::create(['name' => 'Handbook']);
    $path = writeTempDoc('a.md', str_repeat('Alpha content here. ', 30));
    $service = app(IngestionService::class);

    $document = $service->createFromPath($collection, $path, sync: true);
    $firstCount = $document->fresh()->chunk_count;

    // Re-run the sync pipeline on the same document.
    $service->sync($document->fresh());

    expect($document->fresh()->chunk_count)->toBe($firstCount)
        ->and(Document::count())->toBe(1);
});

it('dispatches the extract → chunk → embed chain when queued', function (): void {
    Bus::fake();

    $collection = Collection::create(['name' => 'Handbook']);
    $path = writeTempDoc('b.md', 'Some content.');

    app(IngestionService::class)->createFromPath($collection, $path, sync: false);

    Bus::assertChained([
        ExtractText::class,
        ChunkDocument::class,
        EmbedChunks::class,
    ]);
});

it('marks a document failed when extraction yields no text', function (): void {
    $collection = Collection::create(['name' => 'Handbook']);
    $path = writeTempDoc('empty.md', '   ');

    $service = app(IngestionService::class);
    $document = Document::create([
        'collection_id' => $collection->id,
        'title' => 'Empty',
        'source_path' => $path,
        'mime' => 'text/plain',
    ]);

    expect(fn () => $service->sync($document))->toThrow(RuntimeException::class);
    expect($document->fresh()->status)->toBe(DocumentStatus::Failed);
});
