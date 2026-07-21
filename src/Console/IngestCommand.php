<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Collection;

/**
 * Ingest a file or directory of files into a collection from the CLI.
 */
final class IngestCommand extends Command
{
    protected $signature = 'ai-kit:ingest
        {path : A file or directory to ingest}
        {--collection= : The collection slug to ingest into (created if missing)}
        {--queue : Queue ingestion instead of running it synchronously}';

    protected $description = 'Ingest documents into a ai-kit collection';

    public function handle(IngestionService $service): int
    {
        $path = (string) $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Path [{$path}] does not exist.");

            return self::FAILURE;
        }

        $slug = (string) ($this->option('collection')
            ?: config('ai-kit.default_collection', 'default'));

        $collection = Collection::firstOrCreate(
            ['slug' => $slug],
            ['name' => str($slug)->headline()->toString()],
        );

        $files = is_dir($path) ? $this->filesIn($path) : [$path];

        if ($files === []) {
            $this->warn('No ingestible files found.');

            return self::SUCCESS;
        }

        $sync = ! $this->option('queue');

        foreach ($files as $file) {
            $this->components->task(
                "Ingesting " . basename($file),
                fn () => $service->createFromPath($collection, $file, sync: $sync) !== null,
            );
        }

        $this->newLine();
        $this->info($sync
            ? "Ingested " . count($files) . " file(s) into [{$slug}]."
            : "Queued " . count($files) . " file(s) for ingestion into [{$slug}].");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $dir): array
    {
        $allowed = ['txt', 'md', 'markdown', 'csv', 'html', 'htm', 'pdf', 'docx', 'xlsx'];
        $files = [];

        foreach (scandir($dir) ?: [] as $entry) {
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $allowed, true)) {
                $files[] = $full;
            }
        }

        return $files;
    }
}
