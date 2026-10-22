<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\ReviewAuthority;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval worklist (docs/07 §5): directors/admins see every pending
 * request in the tenant, oldest first; any other staff member sees the
 * review-chain steps waiting for them (or for someone they stand in for).
 * Nobody may decide a request they authored.
 */
class DirectorDocumentWorklistController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant, ListPendingApprovalRequests $listPendingApprovalRequests, ReviewAuthority $authority): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $isOversight = TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, [
            TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN,
        ]);
        $requests = $isOversight
            ? $listPendingApprovalRequests->handle($tenant->id)
            : $listPendingApprovalRequests->forChainReviewer($tenant->id, $user);

        abort_unless($isOversight || $requests->isNotEmpty() || TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, DocumentController::STAFF_ROLES), 403);

        return Inertia::render('portal/documents/director-worklist', [
            'requests' => $requests->map(fn (ApprovalRequest $approvalRequest) => [
                'id' => $approvalRequest->id,
                'documentId' => $approvalRequest->documentVersion->document->id,
                'documentTitle' => $approvalRequest->documentVersion->document->title,
                'workspaceTitle' => $approvalRequest->documentVersion->document->workspace->title,
                'authorName' => $approvalRequest->documentVersion->author->name,
                'ordinal' => $approvalRequest->documentVersion->ordinal,
                'submittedAt' => $approvalRequest->created_at?->toIso8601String(),
                'dueAt' => $approvalRequest->due_at?->toIso8601String(),
                'canDecide' => $authority->canDecide($approvalRequest, $user),
            ])->values(),
        ]);
    }
}
