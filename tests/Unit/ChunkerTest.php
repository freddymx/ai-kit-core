<?php

declare(strict_types=1);

use SolutionForest\AiKit\Chunking\RecursiveTextChunker;
use SolutionForest\AiKit\Data\ChunkOptions;
use SolutionForest\AiKit\Data\TextChunk;

beforeEach(function (): void {
    $this->chunker = new RecursiveTextChunker();
});

it('returns no chunks for empty or whitespace text', function (): void {
    expect($this->chunker->chunk('', new ChunkOptions(100, 20)))->toBe([])
        ->and($this->chunker->chunk("   \n\t ", new ChunkOptions(100, 20)))->toBe([]);
});

it('keeps short text as a single chunk', function (): void {
    $chunks = $this->chunker->chunk('Hello world.', new ChunkOptions(100, 20));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBeInstanceOf(TextChunk::class)
        ->and($chunks[0]->content)->toBe('Hello world.')
        ->and($chunks[0]->index)->toBe(0);
});

it('splits long text into multiple sequentially-indexed chunks under the size limit', function (): void {
    $text = str_repeat('This is a sentence about vectors. ', 60); // ~2040 chars

    $chunks = $this->chunker->chunk($text, new ChunkOptions(300, 50));

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $i => $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(300)
            ->and($chunk->index)->toBe($i);
    }
});

it('carries overlap between adjacent chunks', function (): void {
    $text = '';
    for ($i = 1; $i <= 40; $i++) {
        $text .= "Sentence number {$i} carries some unique token{$i}. ";
    }

    $chunks = $this->chunker->chunk($text, new ChunkOptions(200, 60));

    // The tail of one chunk should reappear at the head of the next.
    $tail = mb_substr($chunks[0]->content, -20);
    expect($chunks[1]->content)->toContain(trim($tail));
})->skip(fn () => false);

it('hard-slices text with no natural separators', function (): void {
    $text = str_repeat('x', 1000);

    $chunks = $this->chunker->chunk($text, new ChunkOptions(250, 0));

    expect(count($chunks))->toBe(4)
        ->and(mb_strlen($chunks[0]->content))->toBe(250);
});
