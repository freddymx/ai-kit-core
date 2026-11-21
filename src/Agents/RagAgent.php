<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Agents;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use SolutionForest\AiKit\AiKit;
use SolutionForest\AiKit\Tools\SearchKnowledgeBase;
use Stringable;

/**
 * The default RAG agent. Reads config to decide behaviour:
 *
 *  - mode "tool"   → tools() returns [SearchKnowledgeBase]; the model searches.
 *  - mode "inject" → tools() returns []; the caller prepends retrieved context
 *                    to the prompt via ContextInjector before running the agent.
 *
 * Instantiate with a collection (and optional tenant) to scope retrieval:
 *
 *   (new RagAgent(collection: 'handbook'))->prompt('...');
 *
 * Consumers typically extend this in App\Ai\SupportAgent to override
 * instructions() per deployment.
 */
class RagAgent implements Agent, Conversational, HasTools
{
    use Promptable {
        prompt as protected promptViaSdk;
        stream as protected streamViaSdk;
    }
    use RemembersConversations;

    public function __construct(
        protected ?string $collection = null,
        protected ?string $tenantId = null,
    ) {
    }

    /**
     * Prompt the agent, defaulting the provider + model to the configured
     * ai-kit chat provider (config ai-kit.chat.*) when the caller doesn't
     * specify them — so AI_KIT_CHAT_PROVIDER/MODEL actually take effect.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return $this->promptViaSdk(
            $prompt,
            $attachments,
            $provider ?? $this->chatProvider(),
            $model ?? $this->chatModel(),
            $timeout,
        );
    }

    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        return $this->streamViaSdk(
            $prompt,
            $attachments,
            $provider ?? $this->chatProvider(),
            $model ?? $this->chatModel(),
            $timeout,
        );
    }

    /**
     * The configured chat provider (e.g. "openrouter"), or null to use the SDK
     * default.
     */
    public function chatProvider(): Lab|array|string|null
    {
        return config('ai-kit.chat.provider') ?: null;
    }

    public function chatModel(): ?string
    {
        return config('ai-kit.chat.model') ?: null;
    }

    /**
     * The agent's step budget: tool round-trips plus the step that writes the
     * answer. Read by the AI SDK's generation loop.
     *
     * The SDK's default is 1.5x the tool count, which gives a one-tool RAG agent
     * only two steps — so a turn that needs a second search runs out mid-loop and
     * ends on an unexecuted tool call. See ConsistentConversationStore for why
     * that used to break the whole conversation.
     */
    public function maxSteps(): ?int
    {
        $configured = (int) config('ai-kit.chat.max_steps', 5);

        return $configured > 0 ? $configured : null;
    }

    public function forCollection(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function forTenant(?string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $template = (string) config('ai-kit.agent.instructions', 'You are a helpful assistant.');

        return str_replace('{app}', (string) config('app.name', 'this application'), $template);
    }

    /**
     * @return iterable<int, object>
     */
    public function tools(): iterable
    {
        if ($this->mode() !== 'tool') {
            return [];
        }

        return [
            new SearchKnowledgeBase(
                collection: $this->collection ?? config('ai-kit.default_collection', 'default'),
                tenantId: $this->tenantId ?? app(AiKit::class)->tenant(),
            ),
        ];
    }

    public function mode(): string
    {
        return config('ai-kit.mode') === 'inject' ? 'inject' : 'tool';
    }

    public function collection(): string
    {
        return $this->collection ?? (string) config('ai-kit.default_collection', 'default');
    }

    public function tenantId(): ?string
    {
        return $this->tenantId ?? app(AiKit::class)->tenant();
    }
}
