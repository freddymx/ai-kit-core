<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use SolutionForest\AiKit\Services\CollectionService;

/**
 * Manage knowledge bases (collections) from the CLI.
 *
 *   ai-kit:collection list
 *   ai-kit:collection new "Handbook" --slug=handbook
 *   ai-kit:collection show handbook
 *   ai-kit:collection delete handbook
 */
final class CollectionCommand extends Command
{
    protected $signature = 'ai-kit:collection
        {action=list : list|new|show|delete}
        {name? : Name (for new) or slug (for show/delete)}
        {--slug= : Slug when creating}
        {--description= : Description when creating}
        {--json}';

    protected $description = 'Manage ai-kit collections (knowledge bases)';

    public function handle(CollectionService $collections): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list($collections),
            'new' => $this->new($collections),
            'show' => $this->show($collections),
            'delete' => $this->delete($collections),
            default => $this->failWith('Unknown action. Use list|new|show|delete.'),
        };
    }

    private function list(CollectionService $collections): int
    {
        $rows = $collections->all()->map(fn ($c) => [
            'slug' => $c->slug,
            'name' => $c->name,
            'documents' => $c->documents_count,
            'chunks' => $c->chunks_count,
        ]);

        if ($this->option('json')) {
            $this->line($rows->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows->isEmpty()
            ? $this->warn('No collections. Create one: ai-kit:collection new "My KB"')
            : $this->table(['Slug', 'Name', 'Docs', 'Chunks'], $rows->map(fn ($r) => array_values($r))->all());

        return self::SUCCESS;
    }

    private function new(CollectionService $collections): int
    {
        $name = (string) $this->argument('name');
        if ($name === '') {
            return $this->failWith('Provide a name: ai-kit:collection new "Handbook"');
        }

        $c = $collections->create($name, $this->option('slug'), $this->option('description'));
        $this->components->info("Created collection [{$c->slug}].");

        return self::SUCCESS;
    }

    private function show(CollectionService $collections): int
    {
        $c = $collections->find((string) $this->argument('name'));
        if (! $c) {
            return $this->failWith('Collection not found.');
        }

        $stats = $collections->stats($c);
        $payload = ['slug' => $c->slug, 'name' => $c->name, 'description' => $c->description, ...$stats];

        $this->option('json')
            ? $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            : $this->table(['Field', 'Value'], collect($payload)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());

        return self::SUCCESS;
    }

    private function delete(CollectionService $collections): int
    {
        $slug = (string) $this->argument('name');
        if (! $collections->find($slug)) {
            return $this->failWith('Collection not found.');
        }

        if (! $this->option('no-interaction') && ! $this->confirm("Delete collection [{$slug}] and all its documents?")) {
            return self::SUCCESS;
        }

        $collections->delete($slug);
        $this->components->info("Deleted [{$slug}].");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
