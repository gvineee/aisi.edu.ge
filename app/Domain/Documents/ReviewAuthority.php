<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentReviewDelegation;
use App\Domain\Documents\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who may decide a pending approval request right now (docs/07 §4):
 * - single-reviewer workspaces: anyone who manages the document;
 * - review chains: the reviewer of an actionable step — in a sequential
 *   chain only the first pending step, in an all-required chain any
 *   pending step — or that reviewer's active delegate;
 * - never the author of the version under review, delegate or not.
 * Chain reviewers (and their delegates) may also read the in-review
 * version, which ordinary readers never see.
 */
class ReviewAuthority
{
    public function __construct(private readonly DocumentAccess $access) {}

    /**
     * @return array<int, int> user ids whose steps $user may decide as delegate
     */
    public function delegatorIds(int $tenantId, User $user): array
    {
        return DocumentReviewDelegation::query()->where('tenant_id', $tenantId)->where('delegate_id', $user->id)
            ->activeAt(Carbon::now())->pluck('delegator_id')->all();
    }

    public function canDecide(ApprovalRequest $request, User $user): bool
    {
        if ($request->state !== ApprovalRequest::STATE_PENDING) {
            return false;
        }

        $version = DocumentVersion::query()->where('tenant_id', $request->tenant_id)->findOrFail($request->document_version_id);

        if ($version->author_id === $user->id) {
            return false;
        }

        $steps = $this->steps($request);

        if ($steps->isEmpty()) {
            $document = Document::query()->where('tenant_id', $request->tenant_id)->findOrFail($version->document_id);

            return $this->access->canManage($document, $user);
        }

        return $this->actionableStep($request, $user, $steps) !== null;
    }

    /**
     * The step $user may decide now and, for a delegate, whose step it is.
     *
     * @param  Collection<int, ApprovalStep>|null  $steps  locked rows when called inside a decision
     * @return array{step: ApprovalStep, onBehalfOf: int|null}|null
     */
    public function actionableStep(ApprovalRequest $request, User $user, ?Collection $steps = null): ?array
    {
        $steps ??= $this->steps($request);
        $pending = $steps->where('state', ApprovalStep::STATE_PENDING)->sortBy('position')->values();
        $mode = $this->modeOf($request);
        $candidates = $mode === 'sequential' ? $pending->take(1) : $pending;

        $own = $candidates->firstWhere('reviewer_id', $user->id);

        if ($own !== null) {
            return ['step' => $own, 'onBehalfOf' => null];
        }

        $delegators = $this->delegatorIds($request->tenant_id, $user);
        $delegated = $candidates->first(fn (ApprovalStep $step) => in_array($step->reviewer_id, $delegators, true));

        return $delegated === null ? null : ['step' => $delegated, 'onBehalfOf' => $delegated->reviewer_id];
    }

    /**
     * Whether $user reviews (or stands in for a reviewer of) the document's
     * pending request, which lets them read the version under review.
     */
    public function isChainReviewer(Document $document, User $user): bool
    {
        if ($document->latest_version_id === null) {
            return false;
        }

        $request = ApprovalRequest::query()->where('tenant_id', $document->tenant_id)
            ->where('document_version_id', $document->latest_version_id)->where('state', ApprovalRequest::STATE_PENDING)->first();

        if ($request === null) {
            return false;
        }

        $reviewers = $this->steps($request)->pluck('reviewer_id')->all();

        return in_array($user->id, $reviewers, true)
            || array_intersect($reviewers, $this->delegatorIds($document->tenant_id, $user)) !== [];
    }

    /**
     * @return Collection<int, ApprovalStep>
     */
    private function steps(ApprovalRequest $request): Collection
    {
        return ApprovalStep::query()->where('tenant_id', $request->tenant_id)->where('approval_request_id', $request->id)->orderBy('position')->get();
    }

    /**
     * The chain mode is stored on the workspace; a request keeps working
     * with its snapshotted steps even if the workspace setting changes,
     * so an all-pending chain is treated as "all" only when configured.
     */
    private function modeOf(ApprovalRequest $request): string
    {
        $version = DocumentVersion::query()->where('tenant_id', $request->tenant_id)->with('document.workspace')->findOrFail($request->document_version_id);

        return $version->document->workspace->review_mode;
    }
}
