<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\Message;
use App\Domain\Communications\Models\MessageDelivery;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Appends a message to an existing conversation. The sender must already be
 * an active participant — never inferred from role, always the real
 * conversation_participants row (docs/09 §5's "recipients resolved
 * server-side" principle applies just as much to the sender check).
 */
class SendMessage
{
    public function handle(int $tenantId, Conversation $conversation, User $sender, string $body): Message
    {
        return DB::transaction(function () use ($tenantId, $conversation, $sender, $body): Message {
            $senderParticipant = ConversationParticipant::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->lockForUpdate()
                ->first();

            if ($senderParticipant === null) {
                throw new NotAConversationParticipant("User {$sender->id} is not a participant of conversation {$conversation->id}.");
            }

            if ($conversation->isArchived()) {
                throw new NotAConversationParticipant("Conversation {$conversation->id} is archived.");
            }

            $message = new Message;
            $message->tenant_id = $tenantId;
            $message->conversation_id = $conversation->id;
            $message->sender_id = $sender->id;
            $message->body = $body;
            $message->save();

            $senderParticipant->last_read_message_id = $message->id;
            $senderParticipant->save();

            $otherParticipants = ConversationParticipant::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $sender->id)
                ->get();

            foreach ($otherParticipants as $participant) {
                $delivery = new MessageDelivery;
                $delivery->tenant_id = $tenantId;
                $delivery->message_id = $message->id;
                $delivery->recipient_id = $participant->user_id;
                $delivery->delivered_at = Carbon::now();
                $delivery->save();
            }

            return $message;
        });
    }
}
