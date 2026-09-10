<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\ApprovalDecision;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Approve or return a pending approval request. Concurrency-safe: the
 * approval_requests row is pessimistically locked (SELECT ... FOR UPDATE)
 * inside the transaction, so a second decision that reads the row while the
 * first is still committing blocks, then sees state != pending and is
 * rejected — never a double-apply, and never a "last write wins" surprise.
 * The row's `lock_version` is bumped every commit as an explicit, testable
 * signal of that guarantee.
 */
class DecideApproval
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(ApprovalRequest $approvalRequest, User $reviewer, string $decision, ?string $comment): ApprovalDecision
    {
        if (! in_array($decision, [ApprovalDecision::DECISION_APPROVED, ApprovalDecision::DECISION_RETURNED], true)) {
            throw new RuntimeException("Unknown decision: {$decision}");
        }

        if ($decision === ApprovalDecision::DECISION_RETURNED && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'დაბრუნებისას განმარტება სავალდებულოა.']);
        }

        return DB::transaction(function () use ($approvalRequest, $reviewer, $decision, $comment) {
            /** @var ApprovalRequest $request */
            $request = ApprovalRequest::query()
                ->where('id', $approvalRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->state !== ApprovalRequest::STATE_PENDING) {
                throw new ApprovalAlreadyDecidedException(
                    "Approval request {$request->id} is no longer pending (state: {$request->state}).",
                );
            }

            $version = $request->documentVersion()->lockForUpdate()->firstOrFail();
            $document = Document::query()->where('id', $version->document_id)->lockForUpdate()->firstOrFail();

            if ($version->author_id === $reviewer->id) {
                throw new RuntimeException('A submitter cannot approve their own document.');
            }

            $request->state = $decision === ApprovalDecision::DECISION_APPROVED
                ? ApprovalRequest::STATE_APPROVED
                : ApprovalRequest::STATE_RETURNED;
            $request->lock_version = $request->lock_version + 1;
            $request->save();

            $approvalDecision = new ApprovalDecision([
                'approval_request_id' => $request->id,
                'reviewer_id' => $reviewer->id,
                'decision' => $decision,
                'comment' => $comment,
                'acted_at' => now(),
            ]);
            $approvalDecision->tenant_id = $document->tenant_id;
            $approvalDecision->save();

            if ($decision === ApprovalDecision::DECISION_APPROVED) {
                $document->status = Document::STATUS_APPROVED;
                $document->published_version_id = $version->id;
            } else {
                $document->status = Document::STATUS_CHANGES_REQUESTED;
            }
            $document->save();

            $this->auditLogger->record($document->tenant_id, "document.{$decision}", $document, $reviewer->id, [
                'approval_request_id' => $request->id,
                'version_id' => $version->id,
                'comment' => $comment,
            ]);

            return $approvalDecision;
        });
    }
}
