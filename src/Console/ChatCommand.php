<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Services\ChatService;

use function Laravel\Prompts\text;

/**
 * Interactive RAG chat REPL in the terminal — grounded answers with citations.
 */
final class ChatCommand extends Command
{
    protected $signature = 'ai-kit:chat
        {collection? : Collection slug to chat against}
        {--agent= : Agent class override}';

    protected $description = 'Chat with a knowledge base from the terminal';

    public function handle(ChatService $chat): int
    {
        $collection = (string) ($this->argument('collection') ?: config('ai-kit.default_collection', 'default'));
        $agentClass = $this->option('agent') ?: null;
        $conversationId = null;

        $this->components->info("Chatting with [{$collection}]. Type 'exit' to quit.");

        while (true) {
            $message = text('You', required: false);

            if ($message === '' || in_array(strtolower($message), ['exit', 'quit', 'q'], true)) {
                break;
            }

            $result = $chat->ask($message, $collection, $conversationId, agentClass: $agentClass);
            $conversationId = $result->conversationId ?? $conversationId;

            $this->newLine();
            $this->line("<fg=cyan>Assistant:</> {$result->answer}");

            if ($result->citations !== []) {
                $sources = collect($result->citations)
                    ->map(fn ($c) => $c['document_title'] . ' #' . $c['chunk_index'])
                    ->unique()->take(5)->implode(', ');
                $this->line("<fg=gray>sources: {$sources}</>");
            }
            $this->newLine();
        }

        $this->components->info('Bye.');

        return self::SUCCESS;
    }
}
