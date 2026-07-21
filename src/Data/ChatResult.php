<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Data;

/**
 * The result of a chat exchange — the shared shape returned by ChatService and
 * consumed by every surface (UI, CLI, HTTP API, MCP).
 */
final readonly class ChatResult
{
    public function __construct(
        public string $answer,
        /** @var array<int, array<string, mixed>> */
        public array $citations = [],
        public ?string $conversationId = null,
        /** @var array{in:int, out:int, total:int} */
        public array $usage = ['in' => 0, 'out' => 0, 'total' => 0],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'citations' => $this->citations,
            'conversation_id' => $this->conversationId,
            'usage' => $this->usage,
        ];
    }
}
