<?php

namespace App\Domain\Portal\Actions;

use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;

/**
 * CLAUDE-PLATFORM-MODULES.md §3's "დღის ცენტრი" read model — aggregates
 * real, actionable items from whichever sources actually exist today.
 * Deliberately a thin adapter over existing queries, not a duplicated
 * table: adding a new source later means adding one more branch here, not
 * a schema migration. An item only ever appears for someone genuinely
 * authorized to act on it (re-derived here, never trusted from a cache).
 */
class BuildDailyActionFeed
{
    public function __construct(
        private readonly ListPendingApprovalRequests $listPendingApprovalRequests,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $tenantId, User $user, string $activeRole): array
    {
        $items = [];

        if (in_array($activeRole, [TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN], true)) {
            foreach ($this->listPendingApprovalRequests->handle($tenantId) as $approvalRequest) {
                $items[] = $this->formatApprovalItem($approvalRequest);
            }
        }

        foreach ($this->unreadConversations($tenantId, $user) as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatApprovalItem(ApprovalRequest $approvalRequest): array
    {
        $document = $approvalRequest->documentVersion->document;

        return [
            'key' => "document_approval:{$approvalRequest->id}",
            'type' => 'document_approval',
            'title' => "დასამტკიცებელია: {$document->title}",
            'contextLabel' => $approvalRequest->documentVersion->author->name,
            'href' => "/documents/{$document->id}",
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unreadConversations(int $tenantId, User $user): array
    {
        $participants = ConversationParticipant::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with(['conversation' => fn ($query) => $query->with('messages')])
            ->get()
            ->filter(fn (ConversationParticipant $participant) => $participant->conversation !== null);

        $items = [];

        foreach ($participants as $participant) {
            $conversation = $participant->conversation;
            $unreadCount = $participant->last_read_message_id === null
                ? $conversation->messages->count()
                : $conversation->messages->where('id', '>', $participant->last_read_message_id)->count();

            if ($unreadCount === 0) {
                continue;
            }

            $items[] = [
                'key' => "unread_conversation:{$conversation->id}",
                'type' => 'unread_message',
                'title' => "წაუკითხავი შეტყობინება: {$conversation->subject}",
                'contextLabel' => $unreadCount > 1 ? "{$unreadCount} ახალი" : null,
                'href' => "/portal/messages/{$conversation->id}",
            ];
        }

        return $items;
    }
}
