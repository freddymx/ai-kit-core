<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Models\Chunk;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Models\Document;
use SolutionForest\AiKit\Support\UsageStats;

/**
 * Print ai-kit usage stats (documents, chunks, tokens, cost).
 */
final class StatsCommand extends Command
{
    protected $signature = 'ai-kit:stats {--days=30 : Window for token/cost totals} {--json}';

    protected $description = 'Show ai-kit knowledge base + token usage statistics';

    public function handle(UsageStats $usage): int
    {
        $days = (int) $this->option('days');
        $tokens = $usage->totals($days);

        $data = [
            'collections' => Collection::query()->count(),
            'documents' => Document::query()->count(),
            'indexed_documents' => Document::query()->where('status', 'indexed')->count(),
            'chunks' => Chunk::query()->count(),
            "tokens_{$days}d" => $tokens['total'],
            "input_tokens_{$days}d" => $tokens['prompt'],
            "output_tokens_{$days}d" => $tokens['completion'],
            "est_cost_{$days}d" => $tokens['cost'],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], collect($data)->map(fn ($v, $k) => [$k, is_float($v) ? '$' . number_format($v, 4) : number_format((int) $v)])->values()->all());

        return self::SUCCESS;
    }
}
