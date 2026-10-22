<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\ApprovalDecision;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\ReviewAuthority;
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
 *
 * With a review chain (docs/07 §4) the decision belongs to a step: the
 * reviewer's own actionable step or, for an active delegate, the
 * delegator's (recorded as on_behalf_of). An approval completes the
 * request only when no pending step remains; a return closes the request
 * at once and cancels the steps nobody has acted on yet.
 */
class DecideApproval
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ManageDocumentAcknowledgements $acknowledgements,
        private readonly ReviewAuthority $authority,
    ) {}

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

            $steps = ApprovalStep::query()->where('tenant_id', $request->tenant_id)->where('approval_request_id', $request->id)
                ->orderBy('position')->lockForUpdate()->get();
            $step = null;
            $onBehalfOf = null;

            if ($steps->isNotEmpty()) {
                $actionable = $this->authority->actionableStep($request, $reviewer, $steps);

                if ($actionable === null) {
                    throw new ApprovalAlreadyDecidedException("No step of approval request {$request->id} is actionable for user {$reviewer->id}.");
                }

                ['step' => $step, 'onBehalfOf' => $onBehalfOf] = $actionable;
                $step->state = $decision === ApprovalDecision::DECISION_APPROVED ? ApprovalStep::STATE_APPROVED : ApprovalStep::STATE_RETURNED;
                $step->acted_at = now();
                $step->save();

                if ($decision === ApprovalDecision::DECISION_RETURNED) {
                    ApprovalStep::query()->where('approval_request_id', $request->id)->where('state', ApprovalStep::STATE_PENDING)
                        ->update(['state' => ApprovalStep::STATE_CANCELLED, 'acted_at' => now()]);
                }
            }

            $completes = $decision === ApprovalDecision::DECISION_RETURNED
                || $steps->where('state', ApprovalStep::STATE_PENDING)->isEmpty();

            if ($completes) {
                $request->state = $decision === ApprovalDecision::DECISION_APPROVED
                    ? ApprovalRequest::STATE_APPROVED
                    : ApprovalRequest::STATE_RETURNED;
            }
            $request->lock_version = $request->lock_version + 1;
            $request->save();

            $approvalDecision = new ApprovalDecision([
                'approval_request_id' => $request->id,
                'approval_step_id' => $step?->id,
                'reviewer_id' => $reviewer->id,
                'on_behalf_of_id' => $onBehalfOf,
                'decision' => $decision,
                'comment' => $comment,
                'acted_at' => now(),
            ]);
            $approvalDecision->tenant_id = $document->tenant_id;
            $approvalDecision->save();

            if (! $completes) {
                $this->auditLogger->record($document->tenant_id, 'document.step_approved', $document, $reviewer->id, [
                    'approval_request_id' => $request->id,
                    'approval_step_id' => $step?->id,
                    'on_behalf_of_id' => $onBehalfOf,
                    'version_id' => $version->id,
                ]);

                return $approvalDecision;
            }

            if ($decision === ApprovalDecision::DECISION_APPROVED) {
                $document->status = Document::STATUS_APPROVED;
                $document->published_version_id = $version->id;
            } else {
                $document->status = Document::STATUS_CHANGES_REQUESTED;
            }
            $document->save();

            if ($decision === ApprovalDecision::DECISION_APPROVED) {
                $this->acknowledgements->onPublished($document, $version, $reviewer->id);
            }

            $this->auditLogger->record($document->tenant_id, "document.{$decision}", $document, $reviewer->id, [
                'approval_request_id' => $request->id,
                'approval_step_id' => $step?->id,
                'on_behalf_of_id' => $onBehalfOf,
                'version_id' => $version->id,
                'comment' => $comment,
            ]);

            return $approvalDecision;
        });
    }
}
