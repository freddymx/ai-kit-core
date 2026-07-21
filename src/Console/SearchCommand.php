<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Data\RetrievalOptions;

/**
 * Raw vector retrieval from the CLI (no LLM) — useful for debugging relevance.
 */
final class SearchCommand extends Command
{
    protected $signature = 'ai-kit:search
        {query : The search query}
        {--collection= : Collection slug (defaults to config)}
        {--top= : Max results}
        {--json : Output JSON}';

    protected $description = 'Search a knowledge base and print the matching chunks';

    public function handle(Retriever $retriever): int
    {
        $opts = RetrievalOptions::fromConfig($this->option('collection'));

        if ($this->option('top')) {
            $opts = $opts->withTopK((int) $this->option('top'));
        }

        $results = $retriever->search((string) $this->argument('query'), $opts);

        if ($this->option('json')) {
            $this->line($results->map(fn ($c) => $c->toArray())->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($results->isEmpty()) {
            $this->warn('No matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['Score', 'Document', 'Chunk', 'Excerpt'],
            $results->map(fn ($c) => [
                number_format($c->score, 3),
                \Illuminate\Support\Str::limit($c->documentTitle, 24),
                $c->chunkIndex,
                \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $c->content), 60),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
