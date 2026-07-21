<?php

declare(strict_types=1);

use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Write a temporary source file and return its path.
 */
function writeTempDoc(string $name, string $body): string
{
    $path = sys_get_temp_dir() . '/aikit_' . uniqid() . "_{$name}";
    file_put_contents($path, $body);

    return $path;
}

/**
 * Seed a collection with three synchronously-ingested sample documents.
 */
function seedCollection(string $slug = 'kb'): Collection
{
    $collection = Collection::create(['name' => ucfirst($slug), 'slug' => $slug]);
    $service = app(IngestionService::class);

    $docs = [
        'Refunds' => str_repeat('Refunds are issued to the original payment method within fourteen days. ', 10),
        'Shipping' => str_repeat('Shipping is free for orders over fifty dollars and takes three days. ', 10),
        'Warranty' => str_repeat('The warranty covers manufacturing defects for two full years. ', 10),
    ];

    foreach ($docs as $title => $body) {
        $service->createFromPath($collection, writeTempDoc('kb.md', $body), title: $title, sync: true);
    }

    return $collection;
}
