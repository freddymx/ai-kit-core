<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Conversations;

use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * A conversation store that keeps tool calls paired with their outputs.
 *
 * Providers reject a request whose history contains a tool call with no
 * matching output — OpenAI answers the /responses call with
 * "No tool output found for function call call_…" (a 400 invalid_request_error
 * on param "input"). Because the history is replayed on every turn, one
 * unpaired call poisons the conversation permanently: every later message in it
 * fails the same way.
 *
 * A turn ends with an unpaired call whenever the agent's step budget runs out
 * while the model is still calling tools: the SDK records that final call but
 * never executes it, so no output is ever produced for it. Raising the budget
 * (see ai-kit.chat.max_steps) makes that rarer, not impossible, so this store
 * enforces the invariant before the turn is persisted: a tool call that has no
 * output — and is not waiting for an approval decision — is never written.
 *
 * The AI SDK's own store already repairs such a conversation when it replays
 * it, so history written before this store was in place stays usable.
 */
class ConsistentConversationStore extends DatabaseConversationStore
{
    /**
     * The steps of a turn, with every unanswered tool call removed.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function stepsFor(AgentPrompt $prompt, AgentResponse $response): Collection
    {
        return parent::stepsFor($prompt, $response)
            ->map(function (array $step): array {
                $calls = collect($step['tool_calls']);

                $paired = $calls->filter(fn (array $toolCall): bool => $this->isPaired($toolCall))->values();

                if ($paired->count() === $calls->count()) {
                    return $step;
                }

                return [
                    ...$step,
                    'tool_calls' => $paired->all(),
                    // The raw provider blocks still name the dropped call, so they
                    // cannot be replayed without it.
                    'replay_blocks' => [],
                ];
            });
    }

    /**
     * A tool call that either has its output recorded, or is still waiting for
     * an approval decision — the SDK has to keep the latter to resume the turn.
     *
     * @param  array<string, mixed>  $toolCall
     */
    private function isPaired(array $toolCall): bool
    {
        return array_key_exists('result', $toolCall)
            || array_key_exists('approval_reason', $toolCall);
    }
}
