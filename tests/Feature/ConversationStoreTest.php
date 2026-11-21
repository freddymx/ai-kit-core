<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsMessages;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use SolutionForest\AiKit\Agents\RagAgent;
use SolutionForest\AiKit\Conversations\ConsistentConversationStore;

uses(RefreshDatabase::class);

/**
 * The two rows the AI SDK writes for one remembered turn.
 *
 * @param  array<int, array<string, mixed>>  $toolCalls
 * @param  array<int, array<string, mixed>>  $toolResults
 */
function storeTurn(string $conversationId, string $question, string $answer, array $toolCalls = [], array $toolResults = []): void
{
    DB::table('agent_conversations')->insertOrIgnore([
        'id' => $conversationId,
        'participant_type' => null,
        'participant_id' => null,
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([['user', $question, []], ['assistant', $answer, $toolCalls]] as [$role, $content, $calls]) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => null,
            'participant_id' => null,
            'agent' => RagAgent::class,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'steps' => json_encode($role === 'assistant' ? [assistantStep($content, $calls, $toolResults)] : []),
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * A step of a stored assistant turn, as the AI SDK v1 writes it: the tool
 * calls it made, each carrying the output it was answered with.
 *
 * @param  array<int, array<string, mixed>>  $toolCalls
 * @param  array<int, array<string, mixed>>  $toolResults
 * @return array<string, mixed>
 */
function assistantStep(string $content, array $toolCalls, array $toolResults = []): array
{
    $results = collect($toolResults)->keyBy('id');

    return [
        'content' => $content,
        'tool_calls' => collect($toolCalls)
            ->map(fn (array $call): array => isset($results[$call['id']])
                ? [...$call, ...Arr::only($results[$call['id']], ['result', 'denied', 'failed'])]
                : $call)
            ->values()
            ->all(),
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ];
}

/**
 * A stored search tool call, as the SDK serialises one.
 *
 * @return array<string, mixed>
 */
function searchCall(string $callId): array
{
    return [
        'id' => 'fc_' . $callId,
        'name' => 'search_knowledge_base',
        'arguments' => ['query' => 'refunds'],
        'result_id' => $callId,
        'reasoning_id' => null,
        'reasoning_summary' => null,
        'reasoning_encrypted_content' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function searchResult(string $callId): array
{
    return array_merge(searchCall($callId), ['result' => 'Refunds take fourteen days.']);
}

/**
 * The OpenAI Responses "input" the gateway would build from these messages.
 *
 * @param  Collection<int, mixed>  $messages
 * @return array<int, array<string, mixed>>
 */
function openAiInput(Collection $messages): array
{
    $mapper = new class
    {
        use MapsMessages;

        public function input(array $messages): array
        {
            return $this->mapMessagesToInput($messages, null, Mockery::mock(Provider::class));
        }

        /** Supplied by the gateway's response parser in production. */
        protected function serializeToolResultOutput(mixed $output): string
        {
            return is_string($output) ? $output : json_encode($output);
        }
    };

    return $mapper->input($messages->all());
}

it('uses the tool-call-consistent conversation store by default', function (): void {
    expect(app(ConversationStore::class))->toBeInstanceOf(ConsistentConversationStore::class);
});

it('leaves an application-provided conversation store alone', function (): void {
    $custom = new class extends DatabaseConversationStore {};

    app()->singleton(ConversationStore::class, fn (): ConversationStore => $custom);
    app()->register(\SolutionForest\AiKit\AiKitServiceProvider::class, force: true);

    expect(app(ConversationStore::class))->toBe($custom);
});

it('replays a conversation whose tool call never got an output without the dangling call', function (): void {
    // What a turn cut off by the step budget leaves behind: the model asked for a
    // second search, the loop ran out of steps, so only the first call has an output.
    $conversationId = (string) Str::uuid7();

    storeTurn(
        $conversationId,
        'How long do refunds take?',
        '',
        toolCalls: [searchCall('call_first'), searchCall('call_Xm8AMgckjF4fX6svUHuoeIJp')],
        toolResults: [searchResult('call_first')],
    );

    $messages = app(ConversationStore::class)->getLatestConversationMessages($conversationId, 100);

    $calls = $messages->whereInstanceOf(AssistantMessage::class)->flatMap(fn ($m) => $m->toolCalls);
    $results = $messages->whereInstanceOf(ToolResultMessage::class)->flatMap(fn ($m) => $m->toolResults);

    expect($calls->pluck('resultId')->all())->toBe(['call_first'])
        ->and($results->pluck('resultId')->all())->toBe(['call_first']);
});

it('builds an OpenAI request in which every function call has its output', function (): void {
    // The exact shape that made OpenAI answer 400 "No tool output found for
    // function call call_Xm8…" on every later message of the conversation.
    $conversationId = (string) Str::uuid7();

    storeTurn($conversationId, 'Hello', 'Hi! Ask me about the knowledge base.');
    storeTurn(
        $conversationId,
        'How long do refunds take?',
        '',
        toolCalls: [searchCall('call_first'), searchCall('call_Xm8AMgckjF4fX6svUHuoeIJp')],
        toolResults: [searchResult('call_first')],
    );

    $input = openAiInput(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 100));

    $calls = collect($input)->where('type', 'function_call')->pluck('call_id');
    $outputs = collect($input)->where('type', 'function_call_output')->pluck('call_id');

    expect($calls->diff($outputs))->toBeEmpty()
        ->and($outputs->diff($calls))->toBeEmpty();

    // The unbroken part of the history still survives the repair.
    expect(collect($input)->where('role', 'user')->count())->toBe(2);
});

it('keeps a fully paired conversation intact', function (): void {
    $conversationId = (string) Str::uuid7();

    storeTurn(
        $conversationId,
        'How long do refunds take?',
        'Fourteen days.',
        toolCalls: [searchCall('call_first')],
        toolResults: [searchResult('call_first')],
    );

    $input = openAiInput(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 100));

    expect(collect($input)->where('type', 'function_call')->pluck('call_id')->all())->toBe(['call_first'])
        ->and(collect($input)->where('type', 'function_call_output')->pluck('call_id')->all())->toBe(['call_first'])
        ->and(collect($input)->where('role', 'assistant')->count())->toBe(1);
});

it('never persists a tool call that has no output', function (): void {
    $conversationId = app(ConversationStore::class)->storeConversation(null, null, 'Refunds');

    $response = new AgentResponse((string) Str::uuid7(), '', new TextUsage, new Meta);
    $response->toolCalls = new Collection([
        ToolCall::fromArray(searchCall('call_first')),
        ToolCall::fromArray(searchCall('call_Xm8AMgckjF4fX6svUHuoeIJp')),
    ]);
    $response->toolResults = new Collection([ToolResult::fromArray(searchResult('call_first'))]);

    app(ConversationStore::class)->storeAssistantMessage(
        $conversationId,
        null,
        null,
        new AgentPrompt(new RagAgent('kb'), 'How long do refunds take?', [], Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class), 'gpt-5'),
        $response,
    );

    $stored = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->first();

    $calls = collect(json_decode($stored->steps, true))->pluck('tool_calls')->flatten(1);

    expect($calls->pluck('result_id')->all())->toBe(['call_first']);

    // The caller's own response object is not mutated by being persisted.
    expect($response->toolCalls)->toHaveCount(2);
});

it('gives the agent a step budget large enough for more than one search', function (): void {
    // 1 tool would otherwise get the SDK default of round(1 * 1.5) = 2 steps:
    // one search, then the answer — and nothing left for a second search.
    expect((new RagAgent('kb'))->maxSteps())->toBe(5);

    config()->set('ai-kit.chat.max_steps', 9);

    expect((new RagAgent('kb'))->maxSteps())->toBe(9);
});
