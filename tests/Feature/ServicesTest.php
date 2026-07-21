<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use SolutionForest\AiKit\Agents\RagAgent;
use SolutionForest\AiKit\Ingestion\Jobs\ExtractText;
use SolutionForest\AiKit\Services\ChatService;
use SolutionForest\AiKit\Services\CollectionService;
use SolutionForest\AiKit\Services\DocumentService;

uses(RefreshDatabase::class);

it('CollectionService creates, finds, counts and deletes', function (): void {
    $svc = app(CollectionService::class);

    $c = $svc->create('Handbook', description: 'Docs');
    expect($svc->find('handbook')->is($c))->toBeTrue()
        ->and($svc->all())->toHaveCount(1)
        ->and($svc->stats($c))->toMatchArray(['documents' => 0, 'chunks' => 0]);

    expect($svc->delete('handbook'))->toBeTrue()
        ->and($svc->find('handbook'))->toBeNull();
});

it('DocumentService ingests, lists chunks, and re-index queues', function (): void {
    $collection = app(CollectionService::class)->create('KB');
    $path = writeTempDoc('p.md', str_repeat('Refund policy content here. ', 30));

    $doc = app(DocumentService::class)->ingestPath($collection, $path, sync: true);

    expect(app(DocumentService::class)->forCollection($collection))->toHaveCount(1)
        ->and(app(DocumentService::class)->chunks($doc)->count())->toBeGreaterThan(0);

    Bus::fake();
    app(DocumentService::class)->reindex($doc); // queued
    Bus::assertChained([ExtractText::class, \SolutionForest\AiKit\Ingestion\Jobs\ChunkDocument::class, \SolutionForest\AiKit\Ingestion\Jobs\EmbedChunks::class]);
});

it('ChatService returns a grounded answer with citations and usage', function (): void {
    seedCollection('kb');
    config()->set('ai-kit.mode', 'tool');
    RagAgent::fake(['Refunds take fourteen days.']);

    $result = app(ChatService::class)->ask('How long do refunds take?', 'kb');

    expect($result->answer)->toContain('fourteen days')
        ->and($result->citations)->not->toBeEmpty()
        ->and($result->usage)->toHaveKeys(['in', 'out', 'total']);

    RagAgent::assertPrompted('How long do refunds take?');
});
