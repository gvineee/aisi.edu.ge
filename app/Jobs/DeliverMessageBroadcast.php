<?php

namespace App\Jobs;

use App\Domain\Communications\Actions\StartConversation;
use App\Domain\Communications\Models\MessageBroadcast;
use App\Domain\Communications\Models\MessageBroadcastRecipient;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers a class announcement: one private conversation per snapshotted
 * guardian. Each recipient row is claimed under a lock and skipped once
 * delivered, so a retry (3 tries, backoff) never sends twice; one failing
 * recipient is recorded and does not stop the others.
 */
class DeliverMessageBroadcast implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $broadcastId) {}

    public function handle(StartConversation $startConversation, CurrentTenant $currentTenant): void
    {
        $broadcast = MessageBroadcast::query()->withoutGlobalScope('tenant')->find($this->broadcastId);

        if ($broadcast === null || $broadcast->status === MessageBroadcast::STATUS_SENT) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($broadcast->tenant_id);
        $previous = $currentTenant->get();
        $currentTenant->set($tenant);

        try {
            $broadcast->status = MessageBroadcast::STATUS_SENDING;
            $broadcast->save();
            $sender = User::query()->findOrFail($broadcast->sender_id);

            $pendingIds = MessageBroadcastRecipient::query()->where('tenant_id', $tenant->id)->where('message_broadcast_id', $broadcast->id)
                ->where('status', '!=', MessageBroadcastRecipient::STATUS_DELIVERED)->pluck('id');

            foreach ($pendingIds as $recipientId) {
                try {
                    DB::transaction(function () use ($recipientId, $tenant, $broadcast, $sender, $startConversation) {
                        $recipient = MessageBroadcastRecipient::query()->whereKey($recipientId)->lockForUpdate()->firstOrFail();

                        if ($recipient->status === MessageBroadcastRecipient::STATUS_DELIVERED) {
                            return;
                        }

                        // Re-check at delivery time: a guardian removed since the
                        // snapshot is not messaged.
                        if (! TenantMembership::userHasActiveRole($tenant->id, $recipient->user_id, TenantMembership::ROLE_GUARDIAN)) {
                            $recipient->status = MessageBroadcastRecipient::STATUS_FAILED;
                            $recipient->failure_reason = 'membership_inactive';
                            $recipient->save();

                            return;
                        }

                        $conversation = $startConversation->handle($tenant->id, $sender, $broadcast->sender_role, User::query()->findOrFail($recipient->user_id),
                            TenantMembership::ROLE_GUARDIAN, $broadcast->subject, $broadcast->body);

                        $recipient->conversation_id = $conversation->id;
                        $recipient->status = MessageBroadcastRecipient::STATUS_DELIVERED;
                        $recipient->failure_reason = null;
                        $recipient->save();
                    });
                } catch (Throwable $e) {
                    MessageBroadcastRecipient::query()->whereKey($recipientId)->update([
                        'status' => MessageBroadcastRecipient::STATUS_FAILED,
                        'failure_reason' => mb_substr(class_basename($e), 0, 250),
                    ]);
                    report($e);
                }
            }

            $delivered = MessageBroadcastRecipient::query()->where('tenant_id', $tenant->id)->where('message_broadcast_id', $broadcast->id)
                ->where('status', MessageBroadcastRecipient::STATUS_DELIVERED)->count();
            $broadcast->delivered_count = $delivered;
            $broadcast->status = $delivered === $broadcast->recipients_count ? MessageBroadcast::STATUS_SENT : MessageBroadcast::STATUS_PARTIAL;
            $broadcast->completed_at = Carbon::now();
            $broadcast->save();
        } finally {
            $previous === null ? $currentTenant->clear() : $currentTenant->set($previous);
        }
    }
}
