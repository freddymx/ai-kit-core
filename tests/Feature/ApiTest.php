<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SolutionForest\AiKit\Agents\RagAgent;

uses(RefreshDatabase::class);

it('lists collections over the API', function (): void {
    seedCollection('kb');

    $this->getJson('/api/ai-kit/collections')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'kb');
});

it('searches over the API', function (): void {
    seedCollection('kb');

    $this->postJson('/api/ai-kit/search', ['query' => 'refunds', 'collection' => 'kb'])
        ->assertOk()
        ->assertJsonStructure(['results' => [['document_title', 'chunk_index', 'score', 'content']]]);
});

it('answers a chat request over the API', function (): void {
    seedCollection('kb');
    config()->set('ai-kit.mode', 'tool');
    RagAgent::fake(['Refunds take fourteen days.']);

    $this->postJson('/api/ai-kit/chat', ['message' => 'How long do refunds take?', 'collection' => 'kb'])
        ->assertOk()
        ->assertJsonPath('answer', 'Refunds take fourteen days.')
        ->assertJsonStructure(['answer', 'citations', 'conversation_id', 'usage']);
});

it('ingests text over the API', function (): void {
    seedCollection('kb');

    $this->postJson('/api/ai-kit/collections/kb/documents', [
        'text' => str_repeat('New API-ingested knowledge. ', 20),
        'title' => 'Via API',
        'sync' => true,
    ])->assertCreated()->assertJsonPath('status', 'indexed');
});

it('returns usage over the API', function (): void {
    $this->getJson('/api/ai-kit/usage')
        ->assertOk()
        ->assertJsonStructure(['totals' => ['total', 'cost'], 'daily']);
});

it('validates chat input', function (): void {
    $this->postJson('/api/ai-kit/chat', [])->assertStatus(422);
});
