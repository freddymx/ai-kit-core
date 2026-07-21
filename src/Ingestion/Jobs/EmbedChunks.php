<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Ingestion\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Document;

/**
 * Step 3 of ingestion: embed the document's chunks, store the vectors in the
 * search index, and mark the document indexed.
 */
final class EmbedChunks implements ShouldQueue
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

        $service->embedPending($document);

        $document->markIndexed((int) $document->chunks()->count());
    }

    public function failed(\Throwable $e): void
    {
        Document::query()->find($this->documentId)?->markFailed($e->getMessage());
    }
}
