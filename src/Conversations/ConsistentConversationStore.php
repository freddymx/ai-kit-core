<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Conversations;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * A conversation store that keeps tool calls and their outputs paired.
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
 * enforces the invariant on both sides of the database:
 *
 *  - on write, unpaired calls and outputs are dropped before they are persisted;
 *  - on read, they are dropped from the replayed history, which also repairs
 *    conversations that were already stored broken.
 */
class ConsistentConversationStore extends DatabaseConversationStore
{
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        return parent::storeAssistantMessage($conversationId, $userId, $prompt, $this->pairedResponse($response));
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->pairedMessages(parent::getLatestConversationMessages($conversationId, $limit));
    }

    /**
     * The response with any unpaired tool call / output removed. The original is
     * left untouched — the caller still owns it.
     */
    protected function pairedResponse(AgentResponse $response): AgentResponse
    {
        $answered = $this->keys($response->toolResults);
        $called = $this->keys($response->toolCalls);

        $toolCalls = $response->toolCalls->filter(fn (ToolCall $call): bool => isset($answered[$this->key($call)]))->values();
        $toolResults = $response->toolResults->filter(fn (ToolResult $result): bool => isset($called[$this->key($result)]))->values();

        if ($toolCalls->count() === $response->toolCalls->count()
            && $toolResults->count() === $response->toolResults->count()) {
            return $response;
        }

        $paired = clone $response;
        $paired->toolCalls = $toolCalls;
        $paired->toolResults = $toolResults;

        return $paired;
    }

    /**
     * A replayed history with any unpaired tool call / output removed, plus the
     * messages left empty by that removal.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    protected function pairedMessages(Collection $messages): Collection
    {
        $answered = $this->keys(
            $messages->whereInstanceOf(ToolResultMessage::class)->flatMap(fn (ToolResultMessage $m): Collection => $m->toolResults)
        );

        $called = $this->keys(
            $messages->whereInstanceOf(AssistantMessage::class)->flatMap(fn (AssistantMessage $m): Collection => $m->toolCalls)
        );

        return $messages
            ->map(fn (Message $message): Message => match (true) {
                $message instanceof AssistantMessage => $this->pairedAssistantMessage($message, $answered),
                $message instanceof ToolResultMessage => $this->pairedToolResultMessage($message, $called),
                default => $message,
            })
            ->reject(fn (Message $message): bool => $this->isEmpty($message))
            ->values();
    }

    /**
     * @param  array<string, true>  $answered
     */
    protected function pairedAssistantMessage(AssistantMessage $message, array $answered): AssistantMessage
    {
        $toolCalls = $message->toolCalls->filter(fn (ToolCall $call): bool => isset($answered[$this->key($call)]))->values();

        return $toolCalls->count() === $message->toolCalls->count()
            ? $message
            : new AssistantMessage((string) $message->content, $toolCalls, $message->providerContentBlocks);
    }

    /**
     * @param  array<string, true>  $called
     */
    protected function pairedToolResultMessage(ToolResultMessage $message, array $called): ToolResultMessage
    {
        $toolResults = $message->toolResults->filter(fn (ToolResult $result): bool => isset($called[$this->key($result)]))->values();

        return $toolResults->count() === $message->toolResults->count()
            ? $message
            : new ToolResultMessage($toolResults);
    }

    /**
     * A message that would contribute nothing to the next request.
     */
    protected function isEmpty(Message $message): bool
    {
        if ($message instanceof AssistantMessage) {
            return $message->toolCalls->isEmpty()
                && $message->providerContentBlocks === []
                && blank($message->content);
        }

        return $message instanceof ToolResultMessage && $message->toolResults->isEmpty();
    }

    /**
     * The pairing keys of the given tool calls / results, as a lookup set.
     *
     * @param  Collection<int, ToolCall|ToolResult>  $items
     * @return array<string, true>
     */
    protected function keys(Collection $items): array
    {
        return $items
            ->mapWithKeys(fn (ToolCall|ToolResult $item): array => [$this->key($item) => true])
            ->all();
    }

    /**
     * The identifier a provider pairs a call with its output on. Gateways send
     * the result id (OpenAI's "call_id") when there is one, else the call id.
     */
    protected function key(ToolCall|ToolResult $item): string
    {
        return $item->resultId ?? $item->id;
    }
}
