<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use SolutionForest\AiKit\Support\UsageStats;

final class ConversationController
{
    public function __construct(private readonly UsageStats $usage)
    {
    }

    /** GET /conversations */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Conversation::query()->withCount('messages')->latest('updated_at')->limit(50)->get()
                ->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'messages' => $c->messages_count,
                    'tokens' => $this->usage->conversationTokens((string) $c->id),
                    'updated_at' => $c->updated_at,
                ])->values()->all(),
        ]);
    }

    /** GET /conversations/{id} */
    public function show(string $id): JsonResponse
    {
        $conversation = Conversation::query()->find($id);
        abort_if($conversation === null, 404, 'Conversation not found.');

        $messages = ConversationMessage::query()->where('conversation_id', $id)->orderBy('created_at')->get();

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'tokens' => $this->usage->conversationTokens((string) $id),
            'messages' => $messages->map(fn (ConversationMessage $m) => [
                'role' => $m->role,
                'content' => $m->content,
                'usage' => UsageStats::messageTokens($m->usage),
                'created_at' => $m->created_at,
            ])->values()->all(),
        ]);
    }
}
