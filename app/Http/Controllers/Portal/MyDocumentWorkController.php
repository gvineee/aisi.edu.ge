<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ჩემი სამუშაო" (docs/07 §5): my drafts, returned-for-changes, and
 * currently-in-review documents, each with the exact next action.
 */
class MyDocumentWorkController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, DocumentController::STAFF_ROLES), 403);

        $documents = Document::query()
            ->where('tenant_id', $tenant->id)
            ->where('owner_id', $user->id)
            ->with(['workspace', 'latestVersion.approvalRequest'])
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('portal/documents/my-work', [
            'documents' => $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'status' => $document->status,
                'workspaceTitle' => $document->workspace->title,
                'updatedAt' => $document->updated_at?->toIso8601String(),
                'nextAction' => match ($document->status) {
                    Document::STATUS_DRAFT => 'submit',
                    Document::STATUS_CHANGES_REQUESTED => 'revise',
                    Document::STATUS_IN_REVIEW => 'wait',
                    Document::STATUS_APPROVED => 'none',
                    default => 'none',
                },
            ])->values(),
        ]);
    }
}
