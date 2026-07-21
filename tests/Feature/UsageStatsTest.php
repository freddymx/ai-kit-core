<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use SolutionForest\AiKit\Support\UsageStats;

uses(RefreshDatabase::class);

function seedMessage(int $prompt, int $completion, string $when = 'now'): void
{
    static $n = 0;
    $n++;
    $conv = Conversation::create(['id' => "c{$n}", 'title' => "Chat {$n}"]);
    ConversationMessage::create([
        'id' => "m{$n}",
        'conversation_id' => $conv->id,
        'agent' => 'TestAgent',
        'role' => 'assistant',
        'content' => 'answer',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
        'meta' => [],
        'created_at' => now()->parse($when),
    ]);
}

it('totals prompt and completion tokens across messages', function (): void {
    seedMessage(100, 20);
    seedMessage(300, 80);

    $totals = app(UsageStats::class)->totals();

    expect($totals['prompt'])->toBe(400)
        ->and($totals['completion'])->toBe(100)
        ->and($totals['total'])->toBe(500)
        ->and($totals['messages'])->toBe(2);
});

it('computes estimated cost from config pricing for the active model', function (): void {
    config()->set('ai-kit.chat.model', 'test/model');
    config()->set('ai-kit.pricing', [
        'test/model' => ['input' => 1.0, 'output' => 2.0], // $ per 1M
        'default' => ['input' => 0.0, 'output' => 0.0],
    ]);

    // 1,000,000 prompt @ $1 + 500,000 completion @ $2 = $1 + $1 = $2
    expect(app(UsageStats::class)->cost(1_000_000, 500_000))->toBe(2.0);
});

it('falls back to default pricing for unlisted models', function (): void {
    config()->set('ai-kit.chat.model', 'unknown/model');
    config()->set('ai-kit.pricing', ['default' => ['input' => 0.0, 'output' => 0.0]]);

    expect(app(UsageStats::class)->cost(1_000_000, 1_000_000))->toBe(0.0);
});

it('builds a zero-filled daily series of the requested length', function (): void {
    seedMessage(50, 10, 'today');

    $daily = app(UsageStats::class)->daily(7);

    expect($daily)->toHaveCount(7)
        ->and($daily[6]['total'])->toBe(60) // last entry = today
        ->and($daily[0]['total'])->toBe(0);
});
