<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ApprovalAlreadyDecidedException;
use App\Domain\Documents\Actions\DecideApproval;
use App\Domain\Documents\Actions\SubmitForReview;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DecideApprovalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentApprovalController extends Controller
{
    public function submit(Request $request, CurrentTenant $currentTenant, Document $document, SubmitForReview $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($document->owner_id === $request->user()->id, 403);

        $action->handle($document, $request->user());

        return redirect()->route('documents.show', $document);
    }

    public function decide(
        DecideApprovalRequest $request,
        CurrentTenant $currentTenant,
        ApprovalRequest $approvalRequest,
        DecideApproval $action,
    ): RedirectResponse {
        $tenant = $currentTenant->get();
        abort_unless($approvalRequest->tenant_id === $tenant->id, 404);
        abort_unless(
            TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, [
                TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN,
            ]),
            403,
        );

        $version = $approvalRequest->documentVersion;

        abort_unless($version->author_id !== $request->user()->id, 403, 'თავად ვერ დაამტკიცებთ საკუთარ დოკუმენტს.');

        try {
            $action->handle($approvalRequest, $request->user(), $request->string('decision')->toString(), $request->string('comment')->toString() ?: null);
        } catch (ApprovalAlreadyDecidedException) {
            throw ValidationException::withMessages(['decision' => 'ეს მოთხოვნა უკვე გადაწყვეტილია.']);
        }

        return redirect()->route('documents.director-worklist');
    }
}
