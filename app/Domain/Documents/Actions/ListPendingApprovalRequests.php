<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\ApprovalRequest;
use Illuminate\Support\Collection;

/**
 * The one query for "pending approval requests in this tenant, oldest
 * first" — shared by the full director worklist page and the director
 * dashboard's preview/count, so they can never drift out of sync.
 */
class ListPendingApprovalRequests
{
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
}
