<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use SolutionForest\AiKit\Services\ConversationService;

uses(RefreshDatabase::class);

/**
 * A minimal participant: the SDK resolves its morph type and key from the model.
 */
class ConversationDeletionTestUser extends Model
{
    protected $guarded = [];
}

/**
 * Seed one conversation with the given number of messages, as the SDK writes them.
 */
function seedDeletableConversation(?string $participantType = null, string|int|null $participantId = null, int $messages = 2): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $participantType,
        'participant_id' => $participantId,
        'title' => 'Deletable conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    for ($i = 0; $i < $messages; $i++) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $id,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent' => 'TestAgent',
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => "Turn {$i}",
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $id;
}

it('deletes a conversation and all its messages by id', function (): void {
    $id = seedDeletableConversation(messages: 3);
    $untouched = seedDeletableConversation(messages: 1);

    expect(app(ConversationService::class)->delete($id))->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $id)->count())->toBe(0)
        ->and(DB::table('agent_conversations')->where('id', $untouched)->exists())->toBeTrue()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $untouched)->count())->toBe(1);
});

it('deletes a conversation when the given participant owns it', function (): void {
    $user = new ConversationDeletionTestUser(['id' => 7]);
    $type = Conversation::participantType($user);
    $key = Conversation::participantKey($user);
    $id = seedDeletableConversation($type, $key);

    expect(app(ConversationService::class)->delete($id, $type, $key))->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $id)->count())->toBe(0);
});

it('refuses to delete a conversation owned by another participant', function (): void {
    $owner = new ConversationDeletionTestUser(['id' => 7]);
    $other = new ConversationDeletionTestUser(['id' => 8]);
    $id = seedDeletableConversation(Conversation::participantType($owner), Conversation::participantKey($owner));

    $service = app(ConversationService::class);

    expect($service->delete($id, Conversation::participantType($other), Conversation::participantKey($other)))->toBeFalse()
        // Same key, different participant type is still a different participant.
        ->and($service->delete($id, stdClass::class, 7))->toBeFalse()
        ->and(DB::table('agent_conversations')->where('id', $id)->exists())->toBeTrue()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $id)->count())->toBe(2);
});

it('deletes a conversation for a user via deleteForParticipant', function (): void {
    $user = new ConversationDeletionTestUser(['id' => 42]);
    $id = seedDeletableConversation(Conversation::participantType($user), Conversation::participantKey($user));

    expect(app(ConversationService::class)->deleteForParticipant($id, $user))->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $id)->count())->toBe(0);
});

it('returns false for an unknown conversation id', function (): void {
    expect(app(ConversationService::class)->delete((string) Str::uuid7()))->toBeFalse();
});

it('cascades message deletion when the Conversation model is deleted directly', function (): void {
    $id = seedDeletableConversation(messages: 3);

    Conversation::query()->findOrFail($id)->delete();

    expect(DB::table('agent_conversations')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $id)->count())->toBe(0);
});

it('deletes only the given participant conversations with deleteAllForParticipant', function (): void {
    $user = new ConversationDeletionTestUser(['id' => 11]);
    $other = new ConversationDeletionTestUser(['id' => 12]);
    $first = seedDeletableConversation(Conversation::participantType($user), Conversation::participantKey($user), 2);
    $second = seedDeletableConversation(Conversation::participantType($user), Conversation::participantKey($user), 1);
    $foreign = seedDeletableConversation(Conversation::participantType($other), Conversation::participantKey($other), 3);

    $service = app(ConversationService::class);

    expect($service->deleteAllForParticipant($user))->toBe(2)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $first)->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $second)->count())->toBe(0)
        ->and(DB::table('agent_conversations')->where('id', $foreign)->exists())->toBeTrue()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $foreign)->count())->toBe(3)
        ->and($service->deleteAllForParticipant(new ConversationDeletionTestUser(['id' => 13])))->toBe(0);
});
