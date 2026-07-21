<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use SolutionForest\AiKit\Support\UsageStats;

/**
 * View the conversation log + token usage from the CLI.
 *
 *   ai-kit:conversation list
 *   ai-kit:conversation show {id}
 */
final class ConversationCommand extends Command
{
    protected $signature = 'ai-kit:conversation
        {action=list : list|show}
        {id? : Conversation id (for show)}
        {--json}';

    protected $description = 'View ai-kit conversations and token usage';

    public function handle(UsageStats $usage): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list($usage),
            'show' => $this->show($usage),
            default => $this->failWith('Unknown action. Use list|show.'),
        };
    }

    private function list(UsageStats $usage): int
    {
        $rows = Conversation::query()->withCount('messages')->latest('updated_at')->limit(50)->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title ?: 'Untitled',
                'messages' => $c->messages_count,
                'tokens' => $usage->conversationTokens((string) $c->id)['total'],
                'updated' => (string) $c->updated_at,
            ]);

        if ($this->option('json')) {
            $this->line($rows->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows->isEmpty()
            ? $this->warn('No conversations.')
            : $this->table(['ID', 'Title', 'Msgs', 'Tokens', 'Updated'], $rows->map(fn ($r) => [Str::limit($r['id'], 12), Str::limit($r['title'], 30), $r['messages'], $r['tokens'], $r['updated']])->all());

        return self::SUCCESS;
    }

    private function show(UsageStats $usage): int
    {
        $id = (string) $this->argument('id');
        $conv = Conversation::query()->find($id);
        if (! $conv) {
            return $this->failWith('Conversation not found.');
        }

        $messages = ConversationMessage::query()->where('conversation_id', $id)->orderBy('created_at')->get();
        $tokens = $usage->conversationTokens($id);

        if ($this->option('json')) {
            $this->line(json_encode([
                'id' => $conv->id,
                'title' => $conv->title,
                'tokens' => $tokens,
                'messages' => $messages->map(fn ($m) => ['role' => $m->role, 'content' => $m->content, 'usage' => UsageStats::messageTokens($m->usage)])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info("Conversation: {$conv->title} — {$tokens['total']} tokens ({$tokens['prompt']} in / {$tokens['completion']} out)");
        foreach ($messages as $m) {
            $tok = UsageStats::messageTokens($m->usage);
            $meta = ($tok['in'] + $tok['out']) > 0 ? " <fg=gray>[{$tok['in']}/{$tok['out']}]</>" : '';
            $this->line("<fg=cyan>" . strtoupper($m->role) . "</>{$meta}: " . Str::limit(preg_replace('/\s+/', ' ', (string) $m->content), 200));
        }

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
