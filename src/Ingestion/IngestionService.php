<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Ingestion;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use SolutionForest\AiKit\Contracts\Chunker;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Contracts\TextExtractor;
use SolutionForest\AiKit\Contracts\VectorIndex;
use SolutionForest\AiKit\Data\ChunkOptions;
use SolutionForest\AiKit\Ingestion\Jobs\ChunkDocument;
use SolutionForest\AiKit\Ingestion\Jobs\EmbedChunks;
use SolutionForest\AiKit\Ingestion\Jobs\ExtractText;
use SolutionForest\AiKit\Models\Chunk;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Models\Document;
use Throwable;

/**
 * The three ingestion steps (extract → chunk → embed) as reusable methods. The
 * queued jobs and the synchronous demo path both call these, so behaviour is
 * identical whichever way a document is ingested.
 */
final class IngestionService
{
    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly Chunker $chunker,
        private readonly Embedder $embedder,
        private readonly VectorIndex $index,
    ) {
    }

    /** Common file extensions → mime types for path-based ingestion. */
    private const MIME_BY_EXTENSION = [
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Create a Document record for a file and ingest it, synchronously (demo /
     * CLI) or via the queue.
     */
    public function createFromPath(
        Collection $collection,
        string $path,
        ?string $title = null,
        bool $sync = false,
    ): Document {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $document = Document::create([
            'tenant_id' => $collection->tenant_id,
            'collection_id' => $collection->getKey(),
            'title' => $title ?? pathinfo($path, PATHINFO_FILENAME),
            'source_path' => $path,
            'mime' => self::MIME_BY_EXTENSION[$extension] ?? 'text/plain',
        ]);

        $sync ? $this->sync($document) : $this->queue($document);

        return $document;
    }

    /**
     * Fetch a web page and ingest it as a document. The HTML is saved to storage
     * so the normal extract → chunk → embed pipeline (sync or queued) can read
     * it; the original URL is kept in the document meta for citations.
     */
    public function createFromUrl(
        Collection $collection,
        string $url,
        ?string $title = null,
        bool $sync = false,
    ): Document {
        $html = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'ai-kit/1.0 (+https://www.solutionforest.com)'])
            ->get($url)
            ->throw()
            ->body();

        $dir = storage_path('app/ai-kit/urls');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . md5($url) . '.html';
        file_put_contents($file, $html);

        $document = Document::create([
            'tenant_id' => $collection->tenant_id,
            'collection_id' => $collection->getKey(),
            'title' => $title ?: ($this->titleFromHtml($html) ?? $this->titleFromUrl($url)),
            'source_path' => $file,
            'mime' => 'text/html',
            'meta' => ['url' => $url],
        ]);

        $sync ? $this->sync($document) : $this->queue($document);

        return $document;
    }

    private function titleFromHtml(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $title !== '' ? mb_substr($title, 0, 255) : null;
        }

        return null;
    }

    private function titleFromUrl(string $url): string
    {
        return trim((string) parse_url($url, PHP_URL_HOST) . (string) parse_url($url, PHP_URL_PATH), '/') ?: $url;
    }

    /**
     * Queue a document for ingestion as a chained job batch. Text is handed
     * between the extract and chunk steps via the cache.
     */
    public function queue(Document $document): void
    {
        $document->markProcessing();

        Bus::chain([
            new ExtractText($document->getKey()),
            new ChunkDocument($document->getKey()),
            new EmbedChunks($document->getKey()),
        ])->onQueue((string) config('ai-kit.ingestion.queue', 'default'))->dispatch();
    }

    /**
     * Ingest a document synchronously — used by ai-kit:demo so the playground
     * works immediately without a queue worker.
     */
    public function sync(Document $document): void
    {
        try {
            $document->markProcessing();
            $text = $this->extract($document);
            $count = $this->chunkAndStore($document, $text);
            $this->embedPending($document);
            $document->markIndexed($count);
        } catch (Throwable $e) {
            $document->markFailed($e->getMessage());

            throw $e;
        }
    }

    public function extract(Document $document): string
    {
        $path = (string) $document->source_path;
        $mime = (string) ($document->mime ?? 'text/plain');

        if ($path === '') {
            throw new \RuntimeException("Document [{$document->title}] has no source file to extract — re-upload the file or add it from a URL.");
        }

        if (! is_file($path)) {
            throw new \RuntimeException("Source file for [{$document->title}] is missing at [{$path}] — on ephemeral filesystems (e.g. Laravel Cloud) uploads must be stored on a persistent disk.");
        }

        $text = trim($this->extractor->extract($path, $mime));

        if ($text === '') {
            throw new \RuntimeException("No text could be extracted from [{$document->title}].");
        }

        return $text;
    }

    /**
     * Replace a document's chunks with freshly chunked content (no embeddings
     * yet). Returns the number of chunks created.
     */
    public function chunkAndStore(Document $document, string $text): int
    {
        // Re-index cleanly: drop old chunks (and their vectors) first.
        $this->index->forgetDocument((int) $document->getKey());
        $document->chunks()->delete();

        $chunks = $this->chunker->chunk($text, ChunkOptions::fromConfig());

        foreach ($chunks as $chunk) {
            Chunk::create([
                'tenant_id' => $document->tenant_id,
                'collection_id' => $document->collection_id,
                'document_id' => $document->getKey(),
                'chunk_index' => $chunk->index,
                'content' => $chunk->content,
            ]);
        }

        return count($chunks);
    }

    /**
     * Embed all of a document's chunks that lack an embedding, store the vectors
     * in the search index, and record the embedding model on each chunk.
     */
    public function embedPending(Document $document): void
    {
        $batchSize = max(1, (int) config('ai-kit.ingestion.embed_batch', 64));

        $document->chunks()
            ->whereNull('embedding')
            ->orderBy('chunk_index')
            ->chunkById($batchSize, function ($chunks): void {
                $vectors = $this->embedder->embed(
                    $chunks->pluck('content')->all()
                );

                foreach ($chunks->values() as $i => $chunk) {
                    $chunk->forceFill([
                        'embedding' => $vectors[$i] ?? null,
                        'embedding_model' => $this->embedder->model(),
                    ])->save();

                    $this->index->store($chunk);
                }
            });
    }
}
