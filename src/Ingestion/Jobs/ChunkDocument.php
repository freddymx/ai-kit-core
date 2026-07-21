<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Ingestion\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Document;

/**
 * Step 2 of ingestion: split the extracted text into chunk rows (no embeddings
 * yet).
 */
final class ChunkDocument implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $documentId)
    {
    }

    public function handle(IngestionService $service): void
    {
        $document = Document::query()->findOrFail($this->documentId);

        $key = ExtractText::textCacheKey($this->documentId);
        $text = Cache::pull($key);

        if (! is_string($text) || $text === '') {
            throw new RuntimeException("Extracted text for document [{$this->documentId}] was not found.");
        }

        $service->chunkAndStore($document, $text);
    }

    public function failed(\Throwable $e): void
    {
        Document::query()->find($this->documentId)?->markFailed($e->getMessage());
    }
}
