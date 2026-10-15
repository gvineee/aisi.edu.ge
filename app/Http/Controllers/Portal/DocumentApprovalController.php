<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ApprovalAlreadyDecidedException;
use App\Domain\Documents\Actions\DecideApproval;
use App\Domain\Documents\Actions\SubmitForReview;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
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
     * Director/admin decide; so may a workspace/document manager granted that
     * level. Nobody decides on a version they authored.
     */
    public function decide(
        DecideApprovalRequest $request,
        CurrentTenant $currentTenant,
        ApprovalRequest $approvalRequest,
        DecideApproval $action,
    ): RedirectResponse {
        $tenant = $currentTenant->get();
        abort_unless($approvalRequest->tenant_id === $tenant->id, 404);

        $version = $approvalRequest->documentVersion;
        abort_unless($this->access->canManage($version->document, $request->user()), 403);
        abort_unless($version->author_id !== $request->user()->id, 403, 'თავად ვერ დაამტკიცებთ საკუთარ დოკუმენტს.');

        try {
            $action->handle($approvalRequest, $request->user(), $request->string('decision')->toString(), $request->string('comment')->toString() ?: null);
        } catch (ApprovalAlreadyDecidedException) {
            throw ValidationException::withMessages(['decision' => 'ეს მოთხოვნა უკვე გადაწყვეტილია.']);
        }

        return $this->access->isOversight($tenant->id, $request->user())
            ? redirect()->route('documents.director-worklist')
            : redirect()->route('documents.show', $version->document_id);
    }
}
