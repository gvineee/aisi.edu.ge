<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ApprovalAlreadyDecidedException;
use App\Domain\Documents\Actions\DecideApproval;
use App\Domain\Documents\Actions\SubmitForReview;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\ReviewAuthority;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DecideApprovalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentApprovalController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function submit(Request $request, CurrentTenant $currentTenant, Document $document, SubmitForReview $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canEdit($document, $request->user()) && $document->archived_at === null, 403);

        $action->handle($document, $request->user());

        return redirect()->route('documents.show', $document);
    }

    /**
     * Single-reviewer workspaces: director/admin or a workspace/document
     * manager decide. Review chains: the reviewer of an actionable step or
     * their active delegate (ReviewAuthority). Nobody decides on a version
     * they authored.
     */
    public function decide(
        DecideApprovalRequest $request,
        CurrentTenant $currentTenant,
        ApprovalRequest $approvalRequest,
        DecideApproval $action,
        ReviewAuthority $authority,
    ): RedirectResponse {
        $tenant = $currentTenant->get();
        abort_unless($approvalRequest->tenant_id === $tenant->id, 404);

        $version = $approvalRequest->documentVersion;
        abort_unless($version->author_id !== $request->user()->id, 403, 'თავად ვერ დაამტკიცებთ საკუთარ დოკუმენტს.');

        if (! $authority->canDecide($approvalRequest, $request->user())) {
            abort_unless($approvalRequest->state !== ApprovalRequest::STATE_PENDING, 403);

            throw ValidationException::withMessages(['decision' => 'ეს მოთხოვნა უკვე გადაწყვეტილია.']);
        }

        try {
            $action->handle($approvalRequest, $request->user(), $request->string('decision')->toString(), $request->string('comment')->toString() ?: null);
        } catch (ApprovalAlreadyDecidedException) {
            throw ValidationException::withMessages(['decision' => 'ეს მოთხოვნა უკვე გადაწყვეტილია.']);
        }

        return $this->access->isOversight($tenant->id, $request->user()) || ! $this->access->canView($version->document, $request->user())
            ? redirect()->route('documents.director-worklist')
            : redirect()->route('documents.show', $version->document_id);
    }
}
