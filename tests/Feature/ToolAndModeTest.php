<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use SolutionForest\AiKit\Agents\RagAgent;
use SolutionForest\AiKit\Pipeline\ContextInjector;
use SolutionForest\AiKit\Tools\SearchKnowledgeBase;

uses(RefreshDatabase::class);

it('exposes a query parameter in the tool schema', function (): void {
    $schema = (new SearchKnowledgeBase('kb'))->schema(new JsonSchemaTypeFactory());

    expect($schema)->toHaveKey('query');
});

it('returns citation-bearing JSON results from the tool', function (): void {
    seedCollection('kb');

    $json = (new SearchKnowledgeBase('kb'))->handle(
        new Laravel\Ai\Tools\Request(['query' => 'How long do refunds take?'])
    );

    $payload = json_decode((string) $json, true);

    expect($payload)->toHaveKey('results')
        ->and($payload['results'])->not->toBeEmpty()
        ->and($payload['results'][0])->toHaveKeys(['document_title', 'document_id', 'chunk_index', 'score', 'content']);
});

it('reports no results gracefully for an unknown collection', function (): void {
    $json = (new SearchKnowledgeBase('missing'))->handle(
        new Laravel\Ai\Tools\Request(['query' => 'anything'])
    );

    expect(json_decode((string) $json, true)['results'])->toBe([]);
});

it('attaches the search tool in tool mode', function (): void {
    config()->set('ai-kit.mode', 'tool');

    $tools = iterator_to_array((new RagAgent('kb'))->tools());

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(SearchKnowledgeBase::class);
});

it('attaches no tools in inject mode', function (): void {
    config()->set('ai-kit.mode', 'inject');

    expect(iterator_to_array((new RagAgent('kb'))->tools()))->toBe([]);
});

it('injects retrieved context into the prompt in inject mode', function (): void {
    seedCollection('kb');

    $prompt = app(ContextInjector::class)->inject('How long do refunds take?', 'kb');

    expect($prompt)->toContain('<context>')
        ->and($prompt)->toContain('Refunds')
        ->and($prompt)->toContain('How long do refunds take?');
});

it('leaves the prompt unchanged when nothing is retrieved', function (): void {
    $prompt = app(ContextInjector::class)->inject('anything', 'empty-collection');

    expect($prompt)->toBe('anything');
});
