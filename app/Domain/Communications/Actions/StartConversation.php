<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a new direct conversation between exactly two users, seeded with
 * the first message. The caller must have already verified `recipient` is
 * one of `ResolveMessageableUsers::forUser()`'s results for `initiator` —
 * this action does not re-derive that relationship itself, so it must never
 * be called with an arbitrary, unchecked recipient id.
 */
class StartConversation
{
    public function handle(
        int $tenantId,
        User $initiator,
        string $initiatorRoleContext,
        User $recipient,
        string $recipientRoleContext,
        string $subject,
        string $body,
    ): Conversation {
        if ($initiator->id === $recipient->id) {
            throw new InvalidArgumentException('Cannot start a conversation with yourself.');
        }

        return DB::transaction(function () use ($tenantId, $initiator, $initiatorRoleContext, $recipient, $recipientRoleContext, $subject, $body): Conversation {
            $conversation = new Conversation;
            $conversation->tenant_id = $tenantId;
            $conversation->subject = $subject;
            $conversation->type = Conversation::TYPE_DIRECT;
            $conversation->created_by = $initiator->id;
            $conversation->save();

            foreach ([
                [$initiator, $initiatorRoleContext],
                [$recipient, $recipientRoleContext],
            ] as [$participantUser, $roleContext]) {
                $participant = new ConversationParticipant;
                $participant->tenant_id = $tenantId;
                $participant->conversation_id = $conversation->id;
                $participant->user_id = $participantUser->id;
                $participant->role_context = $roleContext;
                $participant->joined_at = Carbon::now();
                $participant->save();
            }

            app(SendMessage::class)->handle($tenantId, $conversation, $initiator, $body);

            return $conversation;
        });
    }
}
