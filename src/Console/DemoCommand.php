<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Collection;

/**
 * Seed a demo collection with three sample documents and ingest them
 * synchronously so the Playground works immediately — no queue worker needed.
 */
final class DemoCommand extends Command
{
    protected $signature = 'ai-kit:demo
        {--collection=demo : The demo collection slug}
        {--fresh : Delete the demo collection first}';

    protected $description = 'Seed a demo knowledge base and ingest sample documents';

    public function handle(IngestionService $service): int
    {
        $slug = (string) $this->option('collection');

        if ($this->option('fresh')) {
            Collection::where('slug', $slug)->get()->each->delete();
        }

        $collection = Collection::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Demo Knowledge Base', 'description' => 'Sample ai-kit documentation for the demo playground.'],
        );

        $samples = glob(__DIR__ . '/../../resources/samples/*.md') ?: [];

        if ($samples === []) {
            $this->error('No sample documents found in the package.');

            return self::FAILURE;
        }

        $this->components->info("Seeding demo collection [{$slug}] with " . count($samples) . ' documents…');

        foreach ($samples as $sample) {
            $title = str(pathinfo($sample, PATHINFO_FILENAME))->headline()->toString();

            $this->components->task(
                "Ingesting {$title}",
                fn () => $service->createFromPath($collection, $sample, title: $title, sync: true) !== null,
            );
        }

        $this->newLine();
        $this->components->info('Demo ready. Open the Filament Playground (or <livewire:ai-kit.chat collection="' . $slug . '" />) and ask a question.');

        return self::SUCCESS;
    }
}
