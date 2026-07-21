<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SolutionForest\AiKit\Models\Collection;

uses(RefreshDatabase::class);

it('auto-generates a slug from the name', function (): void {
    $collection = Collection::create(['name' => 'Company Handbook']);

    expect($collection->slug)->toBe('company-handbook');
});

it('allows deleting any collection', function (): void {
    $collection = Collection::create(['name' => 'Scratch', 'slug' => 'scratch']);

    $collection->delete();

    expect(Collection::where('slug', 'scratch')->exists())->toBeFalse();
});
