<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SolutionForest\AiKit\Enums\DocumentStatus;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Collection;

uses(RefreshDatabase::class);

it('fetches a URL and ingests it synchronously', function (): void {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>Refund Policy</title></head><body>'
            . '<p>' . str_repeat('Refunds are issued within fourteen days. ', 30) . '</p>'
            . '<script>ignored()</script></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $collection = Collection::create(['name' => 'Web']);

    $document = app(IngestionService::class)->createFromUrl(
        $collection,
        'https://example.com/refunds',
        sync: true,
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Indexed)
        ->and($document->title)->toBe('Refund Policy')          // from <title>
        ->and($document->mime)->toBe('text/html')
        ->and($document->meta['url'])->toBe('https://example.com/refunds')
        ->and($document->chunks()->whereNotNull('embedding')->count())->toBeGreaterThan(0);

    // Script content must not leak into the extracted text.
    expect($document->chunks->pluck('content')->implode(' '))
        ->toContain('Refunds are issued')
        ->not->toContain('ignored()');
});

it('derives a title from the URL when the page has no title tag', function (): void {
    Http::fake(['*' => Http::response('<html><body><p>' . str_repeat('Body text here. ', 20) . '</p></body></html>')]);

    $collection = Collection::create(['name' => 'Web']);

    $document = app(IngestionService::class)->createFromUrl($collection, 'https://example.com/docs/guide', sync: true);

    expect($document->title)->toContain('example.com');
});
