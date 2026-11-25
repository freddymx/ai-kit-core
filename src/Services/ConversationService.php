<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Services;

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;

/**
 * Delete persisted AI SDK conversations (and their messages) safely.
 *
 * The SDK's ConversationStore contract has no delete method, and its migration
 * adds no ON DELETE CASCADE, so deletion lives here. The provider registers an
 * Eloquent "deleting" observer on Conversation that removes the messages for
 * any delete — including deletes issued by Filament or other app code.
 */
final class ConversationService
{
    /**
     * Delete one conversation by id, optionally checking the owning participant.
     *
     * @param  ?string  $participantType  When set, ownership is required to delete.
     * @param  string|int|null  $participantId  The participant's key (ignored when type is null).
     */
    public function delete(
        string $id,
        ?string $participantType = null,
        string|int|null $participantId = null,
    ): bool {
        if ($participantType !== null) {
            // conversationBelongsTo() is declared by VerifiesConversationOwnership,
            // not by ConversationStore itself; the SDK's database store implements
            // both. An app-provided store that only implements ConversationStore
            // cannot prove ownership, so it must not be allowed to delete.
            $store = app(ConversationStore::class);

            if (! $store instanceof VerifiesConversationOwnership
                || ! $store->conversationBelongsTo($id, $participantType, $participantId)) {
                return false;
            }
        }

        $conversation = Conversation::query()->whereKey($id)->first();

        if ($conversation === null) {
            return false;
        }

        // The provider's deleting observer removes the messages, so deleting the
        // model is enough. The transaction keeps that cascade atomic with the
        // conversation delete.
        return $conversation->getConnection()->transaction(
            fn (): bool => (bool) $conversation->delete(),
        );
    }

    /**
     * Delete one conversation after resolving the participant's morph type/key.
     */
    public function deleteForParticipant(string $id, object $participant): bool
    {
        return $this->delete(
            $id,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
        );
    }

    /**
     * Delete every conversation belonging to a participant.
     *
     * Uses Eloquent deletes so the provider's deleting observer fires for each
     * conversation and cleans up its messages.
     */
    public function deleteAllForParticipant(object $participant): int
    {
        $conversations = Conversation::query()
            ->where('participant_type', Conversation::participantType($participant))
            ->where('participant_id', Conversation::participantKey($participant))
            ->get();

        if ($conversations->isEmpty()) {
            return 0;
        }

        return $conversations->first()->getConnection()->transaction(
            function () use ($conversations): int {
                $deleted = 0;

                foreach ($conversations as $conversation) {
                    if ($conversation->delete()) {
                        $deleted++;
                    }
                }

                return $deleted;
            },
        );
    }
}
