<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspaceReviewer;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends the latest version for review. In a workspace with a review chain
 * the chain is snapshotted into approval_steps: reviewers who are no longer
 * active staff are left out, and the author's own step is skipped (nobody
 * approves their own version). A chain with no independent reviewer left
 * cannot be submitted. The due date comes from the workspace's review_days.
 */
class SubmitForReview
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentAccess $access,
    ) {}

    public function handle(Document $document, User $initiator): ApprovalRequest
    {
        return DB::transaction(function () use ($document, $initiator) {
            $document = Document::query()->where('id', $document->id)->lockForUpdate()->firstOrFail();

            if (! $document->workspace->requiresApproval()) {
                throw ValidationException::withMessages(['document' => 'ამ სივრცის ფაილებს დამტკიცება არ სჭირდება.']);
            }

            if (! in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_CHANGES_REQUESTED], true)) {
                throw ValidationException::withMessages(['document' => 'დოკუმენტი უკვე განხილვაშია ან დამტკიცებულია.']);
            }

            $version = $document->latestVersion;

            if ($version !== null && $version->scan_state === DocumentVersion::SCAN_PENDING) {
                throw ValidationException::withMessages(['document' => 'ფაილი ჯერ მოწმდება — სცადეთ ერთ წუთში.']);
            }

            if ($version === null || ! $version->isUsable()) {
                throw ValidationException::withMessages(['document' => 'აქტიური ვერსია ვერ მოიძებნა ან დაბლოკილია.']);
            }

            if (ApprovalRequest::query()->where('document_version_id', $version->id)->exists()) {
                throw ValidationException::withMessages(['document' => 'ეს ვერსია უკვე გაგზავნილია განსახილველად.']);
            }

            $workspace = $document->workspace;
            $chain = $workspace->usesReviewChain() ? $this->chain($workspace->id, $document->tenant_id) : [];

            if ($workspace->usesReviewChain() && array_diff($chain, [$version->author_id]) === []) {
                throw ValidationException::withMessages(['document' => 'სივრცის რეცენზენტების ჯაჭვში ავტორისგან დამოუკიდებელი აქტიური რეცენზენტი არ არის — მიმართეთ ადმინისტრაციას.']);
            }

            $request = new ApprovalRequest([
                'document_version_id' => $version->id,
                'initiator_id' => $initiator->id,
                'state' => ApprovalRequest::STATE_PENDING,
                'due_at' => $workspace->review_days !== null ? now()->addDays($workspace->review_days) : null,
                'lock_version' => 0,
            ]);
            $request->tenant_id = $document->tenant_id;
            $request->save();

            foreach (array_values($chain) as $position => $reviewerId) {
                $step = new ApprovalStep([
                    'approval_request_id' => $request->id,
                    'position' => $position + 1,
                    'reviewer_id' => $reviewerId,
                    'state' => $reviewerId === $version->author_id ? ApprovalStep::STATE_SKIPPED : ApprovalStep::STATE_PENDING,
                ]);
                $step->tenant_id = $document->tenant_id;
                $step->save();
            }

            $document->status = Document::STATUS_IN_REVIEW;
            $document->save();

            $this->auditLogger->record($document->tenant_id, 'document.submitted_for_review', $document, $initiator->id, [
                'version_id' => $version->id,
                'approval_request_id' => $request->id,
                'reviewer_ids' => $chain,
            ]);

            return $request;
        });
    }

    /**
     * The workspace's reviewers, in order, who are still active staff.
     *
     * @return array<int, int>
     */
    private function chain(int $workspaceId, int $tenantId): array
    {
        return DocumentWorkspaceReviewer::query()->where('tenant_id', $tenantId)->where('workspace_id', $workspaceId)
            ->orderBy('position')->with('user')->get()
            ->filter(fn (DocumentWorkspaceReviewer $reviewer) => $this->access->isStaff($tenantId, $reviewer->user))
            ->pluck('user_id')->values()->all();
    }
}
