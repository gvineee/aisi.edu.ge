<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\ReviewAuthority;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one query for "pending approval requests in this tenant, oldest
 * first" — shared by the full director worklist page and the director
 * dashboard's preview/count, so they can never drift out of sync.
 */
class ListPendingApprovalRequests
{
    public function __construct(private readonly ReviewAuthority $authority) {}

    /**
     * @return Collection<int, ApprovalRequest>
     */
    public function handle(int $tenantId): Collection
    {
        return ApprovalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('state', ApprovalRequest::STATE_PENDING)
            ->with(['documentVersion.document.workspace', 'documentVersion.author', 'initiator'])
            ->oldest('created_at')
            ->get();
    }

    /**
     * Pending requests with a review-chain step $user may decide right now —
     * their own or, as an active delegate, someone else's.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function forChainReviewer(int $tenantId, User $user): Collection
    {
        $reviewerIds = [$user->id, ...$this->authority->delegatorIds($tenantId, $user)];

        return ApprovalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('state', ApprovalRequest::STATE_PENDING)
            ->whereIn('id', ApprovalStep::query()->where('tenant_id', $tenantId)->whereIn('reviewer_id', $reviewerIds)
                ->where('state', ApprovalStep::STATE_PENDING)->select('approval_request_id'))
            ->with(['documentVersion.document.workspace', 'documentVersion.author', 'initiator'])
            ->oldest('created_at')
            ->get()
            ->filter(fn (ApprovalRequest $request) => $this->authority->canDecide($request, $user))
            ->values();
    }
}
