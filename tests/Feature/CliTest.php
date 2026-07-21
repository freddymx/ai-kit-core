<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SolutionForest\AiKit\Models\Collection;

uses(RefreshDatabase::class);

it('creates and lists collections via CLI', function (): void {
    $this->artisan('ai-kit:collection', ['action' => 'new', 'name' => 'Handbook', '--slug' => 'handbook'])
        ->assertSuccessful();

    expect(Collection::where('slug', 'handbook')->exists())->toBeTrue();

    $this->artisan('ai-kit:collection', ['action' => 'list'])
        ->expectsOutputToContain('handbook')
        ->assertSuccessful();
});

it('searches from the CLI', function (): void {
    seedCollection('kb');

    $this->artisan('ai-kit:search', ['query' => 'refunds', '--collection' => 'kb'])
        ->assertSuccessful();
});

it('lists documents for a collection via CLI', function (): void {
    seedCollection('kb');

    $this->artisan('ai-kit:document', ['action' => 'list', '--collection' => 'kb'])
        ->assertSuccessful();
});

it('prints stats via CLI', function (): void {
    seedCollection('kb');

    $this->artisan('ai-kit:stats', ['--json' => true])->assertSuccessful();
});

it('deletes a collection via CLI (no-interaction)', function (): void {
    seedCollection('kb');

    $this->artisan('ai-kit:collection', ['action' => 'delete', 'name' => 'kb', '--no-interaction' => true])
        ->assertSuccessful();

    expect(Collection::where('slug', 'kb')->exists())->toBeFalse();
});
