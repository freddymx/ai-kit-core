<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SolutionForest\AiKit\Agents\RagAgent;

uses(RefreshDatabase::class);

it('answers via the AI SDK agent fake without any API key', function (): void {
    RagAgent::fake(['Refunds take up to fourteen days.']);

    $response = (new RagAgent('kb'))->prompt('How long do refunds take?');

    expect((string) $response->text)->toContain('fourteen days');

    RagAgent::assertPrompted('How long do refunds take?');
});

it('derives instructions from config, substituting the app name', function (): void {
    config()->set('app.name', 'Acme Support');
    config()->set('ai-kit.agent.instructions', 'You are a bot for {app}. Cite sources.');

    expect((string) (new RagAgent())->instructions())->toBe('You are a bot for Acme Support. Cite sources.');
});

it('reports its configured mode', function (): void {
    config()->set('ai-kit.mode', 'inject');
    expect((new RagAgent())->mode())->toBe('inject');

    config()->set('ai-kit.mode', 'tool');
    expect((new RagAgent())->mode())->toBe('tool');
});
