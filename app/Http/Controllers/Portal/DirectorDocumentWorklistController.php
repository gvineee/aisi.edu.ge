<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Director's worklist (docs/07 §5): pending approval requests assigned to
 * this tenant's directors/admins, oldest first — never a request the
 * viewer authored.
 */
class DirectorDocumentWorklistController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(
            TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, [
                TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN,
            ]),
            403,
        );

        $requests = ApprovalRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('state', ApprovalRequest::STATE_PENDING)
            ->with(['documentVersion.document.workspace', 'documentVersion.author', 'initiator'])
            ->oldest('created_at')
            ->get();

        return Inertia::render('portal/documents/director-worklist', [
            'requests' => $requests->map(fn (ApprovalRequest $approvalRequest) => [
                'id' => $approvalRequest->id,
                'documentId' => $approvalRequest->documentVersion->document->id,
                'documentTitle' => $approvalRequest->documentVersion->document->title,
                'workspaceTitle' => $approvalRequest->documentVersion->document->workspace->title,
                'authorName' => $approvalRequest->documentVersion->author->name,
                'ordinal' => $approvalRequest->documentVersion->ordinal,
                'submittedAt' => $approvalRequest->created_at?->toIso8601String(),
                'canDecide' => $approvalRequest->documentVersion->author_id !== $user->id,
            ])->values(),
        ]);
    }
}
