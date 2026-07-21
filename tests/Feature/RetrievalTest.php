<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Data\ChunkCollection;
use SolutionForest\AiKit\Data\RetrievalOptions;
use SolutionForest\AiKit\Retrieval\SqliteVecRetriever;

uses(RefreshDatabase::class);

it('uses the SQLite driver by default', function (): void {
    expect(app(Retriever::class))->toBeInstanceOf(SqliteVecRetriever::class);
});

it('retrieves the most relevant chunk for a query with citation metadata', function (): void {
    seedCollection();

    $results = app(Retriever::class)->search(
        'How long do refunds take?',
        RetrievalOptions::fromConfig('kb'),
    );

    expect($results)->toBeInstanceOf(ChunkCollection::class)
        ->and($results)->not->toBeEmpty();

    $top = $results->first();
    expect($top->documentTitle)->toBe('Refunds')
        ->and($top->score)->toBeGreaterThan(0.05)
        ->and($top->toCitation())->toHaveKeys(['document_id', 'document_title', 'chunk_index', 'score']);
});

it('honours top-k', function (): void {
    seedCollection();

    $results = app(Retriever::class)->search(
        'shipping warranty refunds',
        RetrievalOptions::fromConfig('kb')->withTopK(2),
    );

    expect($results->count())->toBeLessThanOrEqual(2);
});

it('returns an empty collection for an unknown collection slug', function (): void {
    seedCollection();

    $results = app(Retriever::class)->search('anything', RetrievalOptions::fromConfig('does-not-exist'));

    expect($results)->toBeEmpty();
});

it('filters out results below the minimum score', function (): void {
    seedCollection();

    $opts = new RetrievalOptions(topK: 5, collection: 'kb', minScore: 0.999);
    $results = app(Retriever::class)->search('completely unrelated xylophone quokka', $opts);

    expect($results)->toBeEmpty();
});
