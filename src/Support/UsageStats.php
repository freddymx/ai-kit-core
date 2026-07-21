<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Aggregates the token usage the AI SDK already logs on each conversation
 * message (agent_conversation_messages.usage) into dashboard-friendly totals,
 * daily series, and an estimated cost from config('ai-kit.pricing').
 *
 * Usage JSON keys are snake_case: prompt_tokens, completion_tokens, etc.
 */
final class UsageStats
{
    /**
     * Totals over the last $days (null = all time).
     *
     * @return array{prompt:int, completion:int, total:int, cost:float, messages:int}
     */
    public function totals(?int $days = null): array
    {
        $prompt = 0;
        $completion = 0;
        $messages = 0;

        $this->messages($days)->each(function (array $u) use (&$prompt, &$completion, &$messages): void {
            $prompt += $u['prompt'];
            $completion += $u['completion'];
            $messages++;
        });

        return [
            'prompt' => $prompt,
            'completion' => $completion,
            'total' => $prompt + $completion,
            'cost' => $this->cost($prompt, $completion),
            'messages' => $messages,
        ];
    }

    /**
     * Per-day token series for the last $days, oldest first. Every day in the
     * window is present (zero-filled) so charts render a continuous line.
     *
     * @return array<int, array{date:string, prompt:int, completion:int, total:int}>
     */
    public function daily(int $days = 14): array
    {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $series[$date] = ['date' => $date, 'prompt' => 0, 'completion' => 0, 'total' => 0];
        }

        $this->messages($days)->each(function (array $u) use (&$series): void {
            $date = $u['date'];
            if (isset($series[$date])) {
                $series[$date]['prompt'] += $u['prompt'];
                $series[$date]['completion'] += $u['completion'];
                $series[$date]['total'] += $u['prompt'] + $u['completion'];
            }
        });

        return array_values($series);
    }

    /**
     * Token totals for a single conversation.
     *
     * @return array{prompt:int, completion:int, total:int}
     */
    public function conversationTokens(string $conversationId): array
    {
        $prompt = 0;
        $completion = 0;

        ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereNotNull('usage')
            ->get(['usage'])
            ->each(function (ConversationMessage $m) use (&$prompt, &$completion): void {
                $prompt += (int) ($m->usage['prompt_tokens'] ?? 0);
                $completion += (int) ($m->usage['completion_tokens'] ?? 0);
            });

        return ['prompt' => $prompt, 'completion' => $completion, 'total' => $prompt + $completion];
    }

    /**
     * Input/output tokens for a single message's usage payload.
     *
     * @param  array<string, mixed>|null  $usage
     * @return array{in:int, out:int}
     */
    public static function messageTokens(?array $usage): array
    {
        return [
            'in' => (int) ($usage['prompt_tokens'] ?? 0),
            'out' => (int) ($usage['completion_tokens'] ?? 0),
        ];
    }

    /**
     * Estimated USD cost for a token count, using the active chat model's rate
     * (config ai-kit.pricing, $ per 1M tokens). Unlisted models fall back to
     * "default" (0).
     */
    public function cost(int $promptTokens, int $completionTokens, ?string $model = null): float
    {
        $model ??= (string) config('ai-kit.chat.model', 'default');
        $pricing = (array) config('ai-kit.pricing', []);
        $rate = $pricing[$model] ?? $pricing['default'] ?? ['input' => 0.0, 'output' => 0.0];

        return round(
            $promptTokens / 1_000_000 * (float) ($rate['input'] ?? 0)
            + $completionTokens / 1_000_000 * (float) ($rate['output'] ?? 0),
            4,
        );
    }

    /**
     * Normalised per-message usage rows within the window.
     *
     * @return Collection<int, array{date:string, prompt:int, completion:int}>
     */
    private function messages(?int $days): Collection
    {
        return ConversationMessage::query()
            ->where('role', 'assistant')
            ->whereNotNull('usage')
            ->when($days !== null, fn ($q) => $q->where('created_at', '>=', Carbon::today()->subDays($days - 1)))
            ->get(['usage', 'created_at'])
            ->map(fn (ConversationMessage $m): array => [
                'date' => Carbon::parse($m->created_at)->toDateString(),
                'prompt' => (int) ($m->usage['prompt_tokens'] ?? 0),
                'completion' => (int) ($m->usage['completion_tokens'] ?? 0),
            ]);
    }
}
