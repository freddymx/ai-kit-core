<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Services\CollectionService;
use SolutionForest\AiKit\Services\DocumentService;

final class CollectionController
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly DocumentService $documents,
    ) {
    }

    /** GET /collections */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->collections->all()->map(fn (Collection $c) => [
                'slug' => $c->slug,
                'name' => $c->name,
                'description' => $c->description,
                'documents' => $c->documents_count,
                'chunks' => $c->chunks_count,
            ])->values()->all(),
        ]);
    }

    /** GET /collections/{slug} */
    public function show(string $slug): JsonResponse
    {
        $collection = $this->collections->find($slug);
        abort_if($collection === null, 404, 'Collection not found.');

        return response()->json([
            'slug' => $collection->slug,
            'name' => $collection->name,
            'description' => $collection->description,
            'stats' => $this->collections->stats($collection),
        ]);
    }

    /** POST /collections */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $collection = $this->collections->create($data['name'], $data['slug'] ?? null, $data['description'] ?? null);

        return response()->json(['slug' => $collection->slug, 'name' => $collection->name], 201);
    }

    /** GET /collections/{slug}/documents */
    public function documents(string $slug): JsonResponse
    {
        $collection = $this->collections->find($slug);
        abort_if($collection === null, 404, 'Collection not found.');

        return response()->json([
            'data' => $this->documents->forCollection($collection)->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'status' => $d->status->value,
                'chunk_count' => $d->chunk_count,
                'mime' => $d->mime,
                'meta' => $d->meta,
                'created_at' => $d->created_at,
            ])->values()->all(),
        ]);
    }

    /**
     * POST /collections/{slug}/documents — ingest by URL, raw text, or upload.
     */
    public function ingest(Request $request, string $slug): JsonResponse
    {
        $collection = $this->collections->find($slug) ?? $this->collections->firstOrCreate($slug);

        $data = $request->validate([
            'url' => ['nullable', 'url'],
            'text' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:20480'],
            'sync' => ['nullable', 'boolean'],
        ]);

        $sync = (bool) ($data['sync'] ?? false);

        if (! empty($data['url'])) {
            $document = $this->documents->ingestUrl($collection, $data['url'], $data['title'] ?? null, $sync);
        } elseif ($request->hasFile('file')) {
            $document = $this->documents->ingestPath($collection, $request->file('file')->getRealPath(), $data['title'] ?? $request->file('file')->getClientOriginalName(), $sync);
        } elseif (! empty($data['text'])) {
            $path = tempnam(sys_get_temp_dir(), 'aikit_') . '.txt';
            file_put_contents($path, $data['text']);
            $document = $this->documents->ingestPath($collection, $path, $data['title'] ?? 'Untitled', $sync);
        } else {
            abort(422, 'Provide one of: url, file, or text.');
        }

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'status' => $document->status->value,
        ], 201);
    }
}
