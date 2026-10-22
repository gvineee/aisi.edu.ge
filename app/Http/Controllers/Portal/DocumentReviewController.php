<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ManageDocumentReviews;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\DocumentReviewDelegation;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review chains (oversight configures per workspace) and reviewer
 * delegation: director/admin may set up a substitute for anyone, any other
 * staff member only for themselves. Both change who may approve documents,
 * so writes require a recent password.
 */
class DocumentReviewController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function updateChain(Request $request, CurrentTenant $currentTenant, DocumentWorkspace $documentWorkspace, ManageDocumentReviews $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($documentWorkspace->tenant_id === $tenant->id, 404);
        abort_unless($this->access->isOversight($tenant->id, $request->user()), 403);

        $data = $request->validate([
            'review_mode' => ['required', Rule::in(DocumentWorkspace::REVIEW_MODES)],
            'review_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'reviewer_ids' => ['array', 'max:10'],
            'reviewer_ids.*' => ['integer', 'distinct'],
        ]);

        $action->saveChain($documentWorkspace, $data['review_mode'], isset($data['review_days']) ? (int) $data['review_days'] : null,
            array_map('intval', $data['reviewer_ids'] ?? []), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'განხილვის წესი შენახულია.']);
    }

    public function delegations(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless($this->access->isStaff($tenant->id, $user), 403);
        $isOversight = $this->access->isOversight($tenant->id, $user);

        $delegations = DocumentReviewDelegation::query()->where('tenant_id', $tenant->id)
            ->when(! $isOversight, fn ($q) => $q->where(fn ($q) => $q->where('delegator_id', $user->id)->orWhere('delegate_id', $user->id)))
            ->where('ends_at', '>', Carbon::now()->subDays(30))
            ->with(['delegator', 'delegate'])->latest('starts_at')->get();

        return Inertia::render('portal/documents/delegations', [
            'delegations' => $delegations->map(fn (DocumentReviewDelegation $delegation) => [
                'id' => $delegation->id,
                'delegatorName' => $delegation->delegator->name,
                'delegateName' => $delegation->delegate->name,
                'startsAt' => $delegation->starts_at->toIso8601String(),
                'endsAt' => $delegation->ends_at->toIso8601String(),
                'reason' => $delegation->reason,
                'state' => $delegation->revoked_at !== null ? 'revoked'
                    : ($delegation->ends_at->isPast() ? 'ended' : ($delegation->starts_at->isFuture() ? 'scheduled' : 'active')),
                'canRevoke' => $delegation->revoked_at === null && ! $delegation->ends_at->isPast()
                    && ($isOversight || $delegation->delegator_id === $user->id),
            ])->values(),
            'canDelegateForOthers' => $isOversight,
            'staff' => DocumentAccessGrantController::staffOptions($tenant->id),
            'currentUserId' => $user->id,
            'maxDays' => DocumentReviewDelegation::MAX_DAYS,
        ]);
    }

    public function storeDelegation(Request $request, CurrentTenant $currentTenant, ManageDocumentReviews $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless($this->access->isStaff($tenant->id, $user), 403);

        $data = $request->validate([
            'delegator_id' => ['nullable', 'integer'],
            'delegate_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $delegatorId = isset($data['delegator_id']) ? (int) $data['delegator_id'] : $user->id;
        abort_unless($delegatorId === $user->id || $this->access->isOversight($tenant->id, $user), 403);

        $action->delegate($tenant->id, $delegatorId, (int) $data['delegate_id'],
            Carbon::parse($data['starts_at'], 'Asia/Tbilisi')->utc(), Carbon::parse($data['ends_at'], 'Asia/Tbilisi')->utc(),
            trim($data['reason']), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'შემცვლელი დაინიშნა.']);
    }

    public function revokeDelegation(Request $request, CurrentTenant $currentTenant, DocumentReviewDelegation $delegation, ManageDocumentReviews $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless($delegation->tenant_id === $tenant->id, 404);
        abort_unless($delegation->delegator_id === $user->id || $this->access->isOversight($tenant->id, $user), 403);

        $action->revoke($delegation, $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'დელეგირება გაუქმდა.']);
    }
}
