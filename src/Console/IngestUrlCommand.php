<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Collection;
use Throwable;

/**
 * Fetch a web page and ingest it into a collection.
 */
final class IngestUrlCommand extends Command
{
    protected $signature = 'ai-kit:ingest-url
        {url : The URL to fetch and ingest}
        {--collection= : The collection slug to ingest into (created if missing)}
        {--title= : Optional document title (defaults to the page title)}
        {--queue : Queue ingestion instead of running it synchronously}';

    protected $description = 'Fetch a URL and ingest its content into an ai-kit collection';

    public function handle(IngestionService $service): int
    {
        $url = (string) $this->argument('url');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error("Invalid URL: [{$url}].");

            return self::FAILURE;
        }

        $slug = (string) ($this->option('collection') ?: config('ai-kit.default_collection', 'default'));

        $collection = Collection::firstOrCreate(
            ['slug' => $slug],
            ['name' => str($slug)->headline()->toString()],
        );

        $sync = ! $this->option('queue');

        try {
            $document = $service->createFromUrl(
                $collection,
                $url,
                title: $this->option('title') ?: null,
                sync: $sync,
            );
        } catch (Throwable $e) {
            $this->error("Failed to ingest URL: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info($sync
            ? "Ingested [{$document->title}] into [{$slug}]."
            : "Queued [{$document->title}] for ingestion into [{$slug}].");

        return self::SUCCESS;
    }
}
