<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Services;

use Laravel\Ai\Responses\StreamableAgentResponse;
use SolutionForest\AiKit\Agents\RagAgent;
use SolutionForest\AiKit\AiKit;
use SolutionForest\AiKit\Data\ChatResult;
use SolutionForest\AiKit\Pipeline\ContextInjector;

/**
 * The single entry point for "ask a question against a knowledge base".
 *
 * Every surface — the Livewire component, CLI, HTTP API, and MCP server — goes
 * through here, so RAG mode handling, conversation persistence, citations, and
 * usage extraction live in exactly one place.
 */
final class ChatService
{
    public function __construct(private readonly ContextInjector $injector)
    {
    }

    /**
     * Ask a question and get a grounded answer (non-streamed).
     *
     * @param  object|null  $user  Conversation participant; when set, the exchange is persisted.
     */
    public function ask(
        string $message,
        ?string $collection = null,
        ?string $conversationId = null,
        ?object $user = null,
        ?string $agentClass = null,
    ): ChatResult {
        $collection ??= (string) config('ai-kit.default_collection', 'default');
        $agent = $this->makeAgent($agentClass, $collection);

        $citations = $this->citationsFor($message, $collection, $agent->tenantId());

        if ($user !== null) {
            $agent = $conversationId !== null && $conversationId !== ''
                ? $agent->continue($conversationId, $user)
                : $agent->forUser($user);
        }

        $response = $agent->prompt($this->input($agent, $message, $collection));

        return new ChatResult(
            answer: (string) $response->text,
            citations: $citations,
            conversationId: $this->conversationId($response) ?? $conversationId,
            usage: $this->usage($response),
        );
    }

    /**
     * Stream a grounded answer. Returns the SDK's streamable response; the
     * caller iterates TextDelta events (see the HTTP API + ai-kit-ui).
     */
    public function stream(
        string $message,
        ?string $collection = null,
        ?string $conversationId = null,
        ?object $user = null,
        ?string $agentClass = null,
    ): StreamableAgentResponse {
        $collection ??= (string) config('ai-kit.default_collection', 'default');
        $agent = $this->makeAgent($agentClass, $collection);

        if ($user !== null) {
            $agent = $conversationId !== null && $conversationId !== ''
                ? $agent->continue($conversationId, $user)
                : $agent->forUser($user);
        }

        return $agent->stream($this->input($agent, $message, $collection));
    }

    /**
     * Retrieved citations for a message (works in both tool and inject modes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function citationsFor(string $message, ?string $collection = null, ?string $tenantId = null): array
    {
        return $this->injector
            ->retrieve($message, $collection, $tenantId ?? app(AiKit::class)->tenant())
            ->map(fn ($chunk): array => $chunk->toArray())
            ->values()
            ->all();
    }

    private function input(RagAgent $agent, string $message, string $collection): string
    {
        return $agent->mode() === 'inject'
            ? $this->injector->inject($message, $collection, $agent->tenantId())
            : $message;
    }

    private function makeAgent(?string $agentClass, string $collection): RagAgent
    {
        $class = $agentClass ?? config('ai-kit.agent.class', RagAgent::class);
        $tenant = app(AiKit::class)->tenant();

        $agent = new $class($collection, $tenant);

        return $agent instanceof RagAgent ? $agent : new RagAgent($collection, $tenant);
    }

    private function conversationId(object $response): ?string
    {
        return property_exists($response, 'conversationId') && $response->conversationId !== null
            ? (string) $response->conversationId
            : null;
    }

    /**
     * @return array{in:int, out:int, total:int}
     */
    private function usage(object $response): array
    {
        $usage = $response->usage ?? null;
        $in = (int) ($usage->promptTokens ?? 0);
        $out = (int) ($usage->completionTokens ?? 0);

        return ['in' => $in, 'out' => $out, 'total' => $in + $out];
    }
}
