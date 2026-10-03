<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Models\ActionRequest;
use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\Message;
use App\Domain\Communications\Models\MessageDelivery;
use App\Domain\Communications\Models\NotificationPreference;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Jobs\SendMessageEmailNotification;
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

            $schoolName = (string) (Tenant::query()->whereKey($tenantId)->with('brandSetting')->first()?->brandSetting->display_name ?? '');

            foreach ($otherParticipants as $participant) {
                $delivery = new MessageDelivery;
                $delivery->tenant_id = $tenantId;
                $delivery->message_id = $message->id;
                $delivery->recipient_id = $participant->user_id;
                $delivery->channel = MessageDelivery::CHANNEL_IN_APP;
                $delivery->status = 'delivered';
                $delivery->delivered_at = Carbon::now();
                $delivery->save();

                // Email only for recipients who opted in and did not mute
                // this conversation; one row per recipient keeps it idempotent.
                if (! $participant->muted && NotificationPreference::emailEnabled($tenantId, $participant->user_id)) {
                    $email = new MessageDelivery;
                    $email->tenant_id = $tenantId;
                    $email->message_id = $message->id;
                    $email->recipient_id = $participant->user_id;
                    $email->channel = MessageDelivery::CHANNEL_EMAIL;
                    $email->status = 'queued';
                    $email->save();

                    SendMessageEmailNotification::dispatch($email->id, $schoolName, $conversation->subject, $this->conversationUrl($tenantId, $conversation));
                }
            }

            // Replying closes any open "please reply" request addressed to the sender.
            ActionRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversation->id)
                ->where('target_user_id', $sender->id)
                ->where('type', 'reply')
                ->where('status', 'open')
                ->update(['status' => 'done', 'completed_at' => Carbon::now()]);

            return $message;
        });
    }

    /**
     * Inside a web request the current host is the school's; a queued
     * job (e.g. a class announcement) has no request, so the link is built
     * on the tenant's primary domain instead of APP_URL.
     */
    private function conversationUrl(int $tenantId, Conversation $conversation): string
    {
        $path = route('messages.show', $conversation, false);

        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            return url($path);
        }

        $domain = TenantDomain::query()->where('tenant_id', $tenantId)->where('is_primary', true)->value('domain');

        return $domain === null ? url($path) : "https://{$domain}{$path}";
    }
}
