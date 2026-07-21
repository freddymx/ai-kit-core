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
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Document;

/**
 * Step 1 of ingestion: extract plain text from the source file and stash it for
 * the chunking step. Failures mark the document failed.
 */
final class ExtractText implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $documentId)
    {
    }

    public static function textCacheKey(int $documentId): string
    {
        return "ai-kit:ingest:{$documentId}:text";
    }

    public function handle(IngestionService $service): void
    {
        $document = Document::query()->findOrFail($this->documentId);
        $document->markProcessing();

        $text = $service->extract($document);

        Cache::put(self::textCacheKey($this->documentId), $text, now()->addHour());
    }

    public function failed(\Throwable $e): void
    {
        Document::query()->find($this->documentId)?->markFailed($e->getMessage());
    }
}
