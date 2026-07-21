<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SolutionForest\AiKit\Services\CollectionService;
use SolutionForest\AiKit\Services\DocumentService;

/**
 * Manage documents in a collection from the CLI.
 *
 *   ai-kit:document list --collection=handbook
 *   ai-kit:document chunks {id}
 *   ai-kit:document reindex {id}
 *   ai-kit:document delete {id}
 */
final class DocumentCommand extends Command
{
    protected $signature = 'ai-kit:document
        {action=list : list|chunks|reindex|delete}
        {id? : Document id (for chunks/reindex/delete)}
        {--collection= : Collection slug (for list)}
        {--json}';

    protected $description = 'Manage ai-kit documents';

    public function handle(CollectionService $collections, DocumentService $documents): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list($collections, $documents),
            'chunks' => $this->chunks($documents),
            'reindex' => $this->reindex($documents),
            'delete' => $this->delete($documents),
            default => $this->failWith('Unknown action. Use list|chunks|reindex|delete.'),
        };
    }

    private function list(CollectionService $collections, DocumentService $documents): int
    {
        $slug = (string) ($this->option('collection') ?: config('ai-kit.default_collection', 'default'));
        $collection = $collections->find($slug);
        if (! $collection) {
            return $this->failWith("Collection [{$slug}] not found.");
        }

        $rows = $documents->forCollection($collection)->map(fn ($d) => [
            'id' => $d->id,
            'title' => $d->title,
            'status' => $d->status->value,
            'chunks' => $d->chunk_count,
            'mime' => $d->mime,
        ]);

        if ($this->option('json')) {
            $this->line($rows->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows->isEmpty()
            ? $this->warn('No documents.')
            : $this->table(['ID', 'Title', 'Status', 'Chunks', 'Type'], $rows->map(fn ($r) => [$r['id'], Str::limit($r['title'], 30), $r['status'], $r['chunks'], $r['mime']])->all());

        return self::SUCCESS;
    }

    private function chunks(DocumentService $documents): int
    {
        $doc = $documents->find((int) $this->argument('id'));
        if (! $doc) {
            return $this->failWith('Document not found.');
        }

        $chunks = $documents->chunks($doc)->map(fn ($c) => [
            'index' => $c->chunk_index,
            'embedded' => $c->embedding !== null ? 'yes' : 'no',
            'content' => Str::limit(preg_replace('/\s+/', ' ', $c->content), 80),
        ]);

        $this->option('json')
            ? $this->line($chunks->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            : $this->table(['#', 'Embedded', 'Content'], $chunks->map(fn ($r) => array_values($r))->all());

        return self::SUCCESS;
    }

    private function reindex(DocumentService $documents): int
    {
        $doc = $documents->find((int) $this->argument('id'));
        if (! $doc) {
            return $this->failWith('Document not found.');
        }

        $sync = (bool) $this->option('no-interaction');
        $documents->reindex($doc, sync: $sync);
        $this->components->info($sync ? "Re-indexed [{$doc->title}]." : "Queued re-index of [{$doc->title}].");

        return self::SUCCESS;
    }

    private function delete(DocumentService $documents): int
    {
        $doc = $documents->find((int) $this->argument('id'));
        if (! $doc) {
            return $this->failWith('Document not found.');
        }

        $documents->delete($doc);
        $this->components->info("Deleted [{$doc->title}].");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
