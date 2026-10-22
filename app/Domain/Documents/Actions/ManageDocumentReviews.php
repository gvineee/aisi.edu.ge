<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\DocumentReviewDelegation;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Documents\Models\DocumentWorkspaceReviewer;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Workspace review chains and reviewer delegation (docs/07 §4). A chain
 * change applies to submissions made after it; running requests keep the
 * steps they were snapshotted with. A delegation lets one staff member
 * decide another's steps for a bounded window (at most MAX_DAYS) and is
 * never deleted — revoking ends it early and both are audited.
 */
class ManageDocumentReviews
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentAccess $access,
    ) {}

    /**
     * @param  array<int, int>  $reviewerIds  in review order
     */
    public function saveChain(DocumentWorkspace $workspace, string $mode, ?int $reviewDays, array $reviewerIds, User $actor): void
    {
        $reviewerIds = array_values(array_unique(array_map('intval', $reviewerIds)));

        if ($mode !== DocumentWorkspace::REVIEW_SINGLE && $reviewerIds === []) {
            throw ValidationException::withMessages(['reviewer_ids' => 'რეცენზენტების ჯაჭვს მინიმუმ ერთი რეცენზენტი სჭირდება.']);
        }

        $this->assertStaff($workspace->tenant_id, $reviewerIds, 'reviewer_ids');

        DB::transaction(function () use ($workspace, $mode, $reviewDays, $reviewerIds, $actor) {
            $workspace->review_mode = $mode;
            $workspace->review_days = $reviewDays;
            $workspace->save();

            DocumentWorkspaceReviewer::query()->where('tenant_id', $workspace->tenant_id)->where('workspace_id', $workspace->id)->delete();

            if ($mode !== DocumentWorkspace::REVIEW_SINGLE) {
                foreach ($reviewerIds as $index => $userId) {
                    $reviewer = new DocumentWorkspaceReviewer(['workspace_id' => $workspace->id, 'user_id' => $userId, 'position' => $index + 1]);
                    $reviewer->tenant_id = $workspace->tenant_id;
                    $reviewer->save();
                }
            }

            $this->auditLogger->record($workspace->tenant_id, 'document_workspace.review_chain_updated', $workspace, $actor->id, [
                'review_mode' => $mode,
                'review_days' => $reviewDays,
                'reviewer_ids' => $mode === DocumentWorkspace::REVIEW_SINGLE ? [] : $reviewerIds,
            ]);
        });
    }

    public function delegate(int $tenantId, int $delegatorId, int $delegateId, Carbon $startsAt, Carbon $endsAt, string $reason, User $actor): DocumentReviewDelegation
    {
        if ($delegatorId === $delegateId) {
            throw ValidationException::withMessages(['delegate_id' => 'საკუთარ თავზე დელეგირება შეუძლებელია.']);
        }

        $this->assertStaff($tenantId, [$delegatorId], 'delegator_id');
        $this->assertStaff($tenantId, [$delegateId], 'delegate_id');

        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->isPast()) {
            throw ValidationException::withMessages(['ends_at' => 'დასრულება დაწყების შემდეგ და მომავალში უნდა იყოს.']);
        }

        if ($startsAt->diffInDays($endsAt) > DocumentReviewDelegation::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_at' => 'დელეგირება მაქსიმუმ '.DocumentReviewDelegation::MAX_DAYS.' დღით შეიძლება.']);
        }

        $overlaps = DocumentReviewDelegation::query()->where('tenant_id', $tenantId)->where('delegator_id', $delegatorId)
            ->whereNull('revoked_at')->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_at' => 'ამ პერიოდზე ამ თანამშრომელს უკვე ჰყავს შემცვლელი.']);
        }

        $delegation = new DocumentReviewDelegation([
            'delegator_id' => $delegatorId,
            'delegate_id' => $delegateId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
        $delegation->tenant_id = $tenantId;
        $delegation->save();

        $this->auditLogger->record($tenantId, 'document_review.delegated', $delegation, $actor->id, [
            'delegator_id' => $delegatorId,
            'delegate_id' => $delegateId,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ]);

        return $delegation;
    }

    public function revoke(DocumentReviewDelegation $delegation, User $actor): void
    {
        if ($delegation->revoked_at !== null) {
            return;
        }

        $delegation->revoked_at = now();
        $delegation->save();

        $this->auditLogger->record($delegation->tenant_id, 'document_review.delegation_revoked', $delegation, $actor->id, [
            'delegator_id' => $delegation->delegator_id,
            'delegate_id' => $delegation->delegate_id,
        ]);
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function assertStaff(int $tenantId, array $userIds, string $field): void
    {
        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            if (! $this->access->isStaff($tenantId, $user)) {
                throw ValidationException::withMessages([$field => 'არჩეული პირი ამ სკოლის აქტიური თანამშრომელი არ არის.']);
            }
        }

        if (User::query()->whereIn('id', $userIds)->count() !== count($userIds)) {
            throw ValidationException::withMessages([$field => 'არჩეული პირი ვერ მოიძებნა.']);
        }
    }
}
