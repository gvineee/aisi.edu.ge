<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\Message;
use App\Domain\Communications\Models\MessageDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Marks every message in a conversation as read for one participant.
 * Read-receipt visibility (who has read what) is only ever shown to a
 * message's own sender — see docs/09-design-source-map.md §4.
 */
class MarkConversationRead
{
    public function handle(int $tenantId, Conversation $conversation, User $user): void
    {
        DB::transaction(function () use ($tenantId, $conversation, $user): void {
            $participant = ConversationParticipant::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                throw new NotAConversationParticipant("User {$user->id} is not a participant of conversation {$conversation->id}.");
            }

            $latestMessageId = Message::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversation->id)
                ->max('id');

            if ($latestMessageId !== null) {
                $participant->last_read_message_id = $latestMessageId;
                $participant->save();
            }

            MessageDelivery::query()
                ->where('tenant_id', $tenantId)
                ->where('recipient_id', $user->id)
                ->whereNull('read_at')
                ->whereHas('message', fn ($query) => $query->where('conversation_id', $conversation->id))
                ->update(['read_at' => now()]);
        });
    }
}
