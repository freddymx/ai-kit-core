<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\StreamedResponse;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Data\RetrievalOptions;
use SolutionForest\AiKit\Services\ChatService;
use Throwable;

final class ChatController
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    /**
     * POST /chat — grounded answer + citations (non-streamed).
     */
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'collection' => ['nullable', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->chat->ask(
            $data['message'],
            $data['collection'] ?? null,
            $data['conversation_id'] ?? null,
            user: $request->user(),
        );

        return response()->json($result->toArray());
    }

    /**
     * POST /chat/stream — Server-Sent Events (token-by-token).
     */
    public function stream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'collection' => ['nullable', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        return response()->stream(function () use ($data, $user): void {
            $full = '';
            $conversationId = null;

            try {
                $stream = $this->chat->stream($data['message'], $data['collection'] ?? null, $data['conversation_id'] ?? null, user: $user);

                foreach ($stream as $event) {
                    if ($event instanceof TextDelta) {
                        $full .= $event->delta;
                        $this->sse('delta', ['delta' => $event->delta]);
                    }
                }

                $conversationId = $stream->conversationId ?? null;
            } catch (Throwable $e) {
                report($e);
                $this->sse('error', ['message' => 'Generation failed.']);
            }

            $this->sse('done', ['content' => $full, 'conversation_id' => $conversationId]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /search — raw retrieval (no LLM).
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:8000'],
            'collection' => ['nullable', 'string', 'max:255'],
            'top' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $opts = RetrievalOptions::fromConfig($data['collection'] ?? null);
        if (isset($data['top'])) {
            $opts = $opts->withTopK((int) $data['top']);
        }

        $results = app(Retriever::class)->search($data['query'], $opts);

        return response()->json(['results' => $results->map(fn ($c) => $c->toArray())->values()->all()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR) . "\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
}
