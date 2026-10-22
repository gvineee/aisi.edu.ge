<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Documents\Actions\UpdateDocumentDetails;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Models\ApprovalDecision;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAccessGrant;
use App\Domain\Documents\Models\DocumentAccessLevel;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Documents\ReviewAuthority;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DocumentDetailsRules;
use App\Http\Requests\Portal\StoreDocumentRequest;
use App\Http\Requests\Portal\UpdateDocumentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ფაილების ცენტრი" — internal staff documents: list/search/filter, detail,
 * upload and metadata. Only tenant staff (not guardian/student) get in at
 * all; every visibility decision is DocumentAccess's, so the list and the
 * detail page apply the same rules.
 */
class DocumentController extends Controller
{
    /**
     * @var array<int, string>
     */
    public const STAFF_ROLES = DocumentAccess::STAFF_ROLES;

    private const SORTS = ['updated', 'document_date_desc', 'document_date_asc', 'created_desc', 'title'];

    private const EXPIRY_WINDOW_DAYS = 30;

    public function __construct(private readonly DocumentAccess $access) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless($this->access->isStaff($tenant->id, $user), 403);

        $isOversight = $this->access->isOversight($tenant->id, $user);
        $workspaceLevels = $this->access->workspaceLevels($tenant->id, $user);
        $filters = $this->filters($request);
        $today = Carbon::now('Asia/Tbilisi')->startOfDay();

        $query = $this->access->constrainVisible(Document::query(), $tenant->id, $user)
            ->with(['workspace', 'category', 'owner'])
            ->when($filters['archived'] !== '1', fn ($q) => $q->whereNull('documents.archived_at'), fn ($q) => $q->whereNotNull('documents.archived_at'))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('documents.title', 'like', '%'.$filters['search'].'%')
                ->orWhere('documents.description', 'like', '%'.$filters['search'].'%')))
            ->when($filters['workspace_id'] !== '', fn ($q) => $q->where('documents.workspace_id', (int) $filters['workspace_id']))
            ->when($filters['category_id'] !== '', fn ($q) => $q->where('documents.category_id', (int) $filters['category_id']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('documents.status', $filters['status']))
            ->when($filters['owner_id'] !== '', fn ($q) => $q->where('documents.owner_id', (int) $filters['owner_id']))
            ->when($filters['date_from'] !== '', fn ($q) => $q->whereDate('documents.document_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($q) => $q->whereDate('documents.document_date', '<=', $filters['date_to']))
            ->when($filters['expiring'] === '1', fn ($q) => $q->whereNotNull('documents.expires_on')
                ->whereDate('documents.expires_on', '<=', $today->copy()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString()));

        match ($filters['sort']) {
            'document_date_desc' => $query->orderByRaw('documents.document_date is null')->orderByDesc('documents.document_date'),
            'document_date_asc' => $query->orderByRaw('documents.document_date is null')->orderBy('documents.document_date'),
            'created_desc' => $query->orderByDesc('documents.created_at'),
            'title' => $query->orderBy('documents.title'),
            default => $query->orderByDesc('documents.updated_at'),
        };

        $page = $query->orderByDesc('documents.id')->paginate(30)->withQueryString();

        $managedWorkspaceIds = array_keys(array_filter($workspaceLevels, fn (int $level) => $level >= DocumentAccessLevel::MANAGE));
        $expiringCount = $managedWorkspaceIds === [] ? 0 : $this->access->constrainVisible(Document::query(), $tenant->id, $user)
            ->whereIn('documents.workspace_id', $managedWorkspaceIds)
            ->whereNull('documents.archived_at')
            ->whereNotNull('documents.expires_on')
            ->whereDate('documents.expires_on', '>=', $today->toDateString())
            ->whereDate('documents.expires_on', '<=', $today->copy()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString())
            ->count();

        $ownerIds = $this->access->constrainVisible(Document::query(), $tenant->id, $user)->distinct()->pluck('documents.owner_id');

        $workspaces = DocumentWorkspace::query()->where('tenant_id', $tenant->id)->orderBy('title')->get()
            ->filter(fn (DocumentWorkspace $workspace) => ($workspaceLevels[$workspace->id] ?? 0) >= DocumentAccessLevel::VIEW
                || ($workspace->isRestricted() && $this->hasVisibleDocumentIn($tenant->id, $user, $workspace)));

        return Inertia::render('portal/documents/index', [
            'workspaces' => $workspaces->map(fn (DocumentWorkspace $workspace) => [
                'id' => $workspace->id,
                'title' => $workspace->title,
                'classification' => $workspace->classification,
                'visibility' => $workspace->visibility,
                'requiresApproval' => $workspace->requiresApproval(),
                'isArchived' => $workspace->archived_at !== null,
                'canCreate' => $this->access->canCreateIn($workspace, $user),
            ])->values(),
            'categories' => $this->categories($tenant->id, true),
            'uploaders' => User::query()->whereIn('id', $ownerIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $owner) => ['id' => $owner->id, 'name' => $owner->name])->values(),
            'documents' => collect($page->items())->map(fn (Document $document) => $this->formatSummary($document))->values(),
            'pagination' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'prevUrl' => $page->previousPageUrl(),
                'nextUrl' => $page->nextPageUrl(),
            ],
            'filters' => $filters,
            'expiringCount' => $expiringCount,
            'isDirectorOrAdmin' => $isOversight,
            'myReviewCount' => $isOversight ? 0 : app(ListPendingApprovalRequests::class)->forChainReviewer($tenant->id, $user)->count(),
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Document $document, ReviewAuthority $authority): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        $user = $request->user();
        // A review-chain reviewer (or delegate) reads the version under review
        // even without access to the document otherwise.
        $isChainReviewer = $authority->isChainReviewer($document, $user);
        abort_unless($this->access->canView($document, $user) || $isChainReviewer, 403);

        $document->load(['workspace', 'category', 'owner', 'latestVersion', 'publishedVersion', 'versions.author',
            'versions.approvalRequest.decisions.reviewer', 'versions.approvalRequest.decisions.onBehalfOf', 'versions.approvalRequest.steps.reviewer']);
        $pendingRequest = $document->latestVersion?->approvalRequest;
        $pendingRequest = $pendingRequest?->state === ApprovalRequest::STATE_PENDING ? $pendingRequest : null;

        $canEdit = $this->access->canEdit($document, $user);
        $canManage = $this->access->canManage($document, $user);
        $isArchived = $document->archived_at !== null;
        $requiresApproval = $document->workspace->requiresApproval();

        // A reader with VIEW only ever sees the published version; a chain
        // reviewer also sees the version under review.
        $versions = $canEdit
            ? $document->versions
            : $document->versions->filter(fn (DocumentVersion $version) => $version->id === $document->published_version_id
                || ($isChainReviewer && $version->id === $document->latest_version_id))->values();

        $approvalHistory = $canEdit || $isChainReviewer ? $document->versions->flatMap(fn (DocumentVersion $version) => $version->approvalRequest !== null ? [$version->approvalRequest] : []) : collect();

        return Inertia::render('portal/documents/show', [
            'document' => $this->formatSummary($document) + [
                'description' => $document->description,
                'categoryId' => $document->category_id,
                'workspaceRequiresApproval' => $requiresApproval,
                'workspaceIsConfidential' => $document->workspace->isConfidential(),
            ],
            'versions' => $versions->map(fn (DocumentVersion $version) => $this->formatVersion($document, $version))->values(),
            'approvalHistory' => $approvalHistory->map(fn (ApprovalRequest $approvalRequest) => $this->formatApprovalHistoryEntry($approvalRequest))->values(),
            'canUpload' => $canEdit && ! $isArchived,
            'canEditDetails' => $canEdit && ! $isArchived,
            'canManage' => $canManage,
            'canSubmit' => $canEdit && ! $isArchived && $requiresApproval
                && in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_CHANGES_REQUESTED], true),
            'canDecide' => $document->status === Document::STATUS_IN_REVIEW && $pendingRequest !== null
                && $authority->canDecide($pendingRequest, $user),
            'reviewMode' => $document->workspace->review_mode,
            'reviewDueAt' => $pendingRequest?->due_at?->toIso8601String(),
            'categories' => $this->categories($tenant->id, false),
            'grants' => $canManage ? DocumentAccessGrantController::grantsFor($tenant->id, DocumentAccessGrant::RESOURCE_DOCUMENT, $document->id) : [],
            'staff' => $canManage ? DocumentAccessGrantController::staffOptions($tenant->id) : [],
            'roleOptions' => $canManage ? DocumentAccessGrantController::roleOptions() : [],
            'templateSource' => $document->template_version_id === null ? null : DocumentVersion::query()->where('tenant_id', $tenant->id)
                ->whereKey($document->template_version_id)->with('document')->first()?->document?->title,
        ] + DocumentAcknowledgementController::documentProps($document, $user, $canManage));
    }

    public function store(StoreDocumentRequest $request, CurrentTenant $currentTenant, CreateDraftDocument $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless($this->access->isStaff($tenant->id, $user), 403);

        $workspace = DocumentWorkspace::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $request->integer('workspace_id'))
            ->firstOrFail();

        abort_unless($this->access->canCreateIn($workspace, $user), 403);

        $responsible = $request->integer('responsible_id')
            ? User::query()->whereIn('id', TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_active', true)->select('user_id'))
                ->find($request->integer('responsible_id'))
            : null;

        $document = $action->handle(
            tenantId: $tenant->id,
            workspace: $workspace,
            owner: $user,
            type: $request->string('type')->toString() ?: 'file',
            title: $request->string('title')->toString(),
            file: $request->file('file'),
            responsible: $responsible,
            metadata: $this->details($request, $tenant->id),
        );

        return redirect()->route('documents.show', $document)->with('documentId', $document->id);
    }

    public function update(UpdateDocumentRequest $request, CurrentTenant $currentTenant, Document $document, UpdateDocumentDetails $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        $user = $request->user();
        abort_unless($this->access->canEdit($document, $user) && $document->archived_at === null, 403);

        $changes = ['title' => $request->string('title')->toString()] + $this->details($request, $tenant->id);

        // Narrowing or widening who sees a document is a manager's call.
        if (! $this->access->canManage($document, $user)) {
            unset($changes['visibility']);
        }

        $action->update($document, $changes, $user);

        return redirect()->route('documents.show', $document)->with('toast', ['type' => 'success', 'message' => 'მონაცემები შენახულია.']);
    }

    public function archive(Request $request, CurrentTenant $currentTenant, Document $document, UpdateDocumentDetails $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canManage($document, $request->user()), 403);
        $request->validate(['archived' => ['required', 'boolean']]);

        $action->setArchived($document, $request->boolean('archived'), $request->user());

        return redirect()->route('documents.show', $document)->with('toast', [
            'type' => 'success', 'message' => $request->boolean('archived') ? 'დოკუმენტი დაარქივდა.' : 'დოკუმენტი არქივიდან დაბრუნდა.',
        ]);
    }

    /**
     * @return array{category_id: int|null, description: string|null, document_date: string|null, expires_on: string|null, tags: array<int, string>|null, visibility: string}
     */
    private function details(Request $request, int $tenantId): array
    {
        $categoryId = $request->integer('category_id') ?: null;

        if ($categoryId !== null && ! DocumentCategory::query()->where('tenant_id', $tenantId)->whereKey($categoryId)->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['category_id' => 'კატეგორია ვერ მოიძებნა.']);
        }

        return [
            'category_id' => $categoryId,
            'description' => $request->string('description')->trim()->toString() ?: null,
            'document_date' => $request->string('document_date')->toString() ?: null,
            'expires_on' => $request->string('expires_on')->toString() ?: null,
            'tags' => DocumentDetailsRules::tags($request->string('tags')->toString()),
            'visibility' => $request->string('visibility')->toString() ?: Document::VISIBILITY_WORKSPACE,
        ];
    }

    /**
     * @return array{search: string, workspace_id: string, category_id: string, status: string, owner_id: string, date_from: string, date_to: string, sort: string, expiring: string, archived: string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string($key)->toString()) === 1 ? $request->string($key)->toString() : '';
        $id = fn (string $key) => ctype_digit($request->string($key)->toString()) ? $request->string($key)->toString() : '';
        $sort = $request->string('sort')->toString();

        return [
            'search' => mb_substr($request->string('search')->trim()->toString(), 0, 100),
            'workspace_id' => $id('workspace_id'),
            'category_id' => $id('category_id'),
            'status' => in_array($request->string('status')->toString(), [Document::STATUS_DRAFT, Document::STATUS_IN_REVIEW, Document::STATUS_CHANGES_REQUESTED, Document::STATUS_APPROVED, Document::STATUS_ACTIVE], true)
                ? $request->string('status')->toString() : '',
            'owner_id' => $id('owner_id'),
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'updated',
            'expiring' => $request->string('expiring')->toString() === '1' ? '1' : '',
            'archived' => $request->string('archived')->toString() === '1' ? '1' : '',
        ];
    }

    private function hasVisibleDocumentIn(int $tenantId, User $user, DocumentWorkspace $workspace): bool
    {
        return $this->access->constrainVisible(Document::query(), $tenantId, $user)->where('documents.workspace_id', $workspace->id)->exists();
    }

    /**
     * @return array<int, array{id: int, name: string, isArchived: bool}>
     */
    private function categories(int $tenantId, bool $includeArchived): array
    {
        return DocumentCategory::query()->where('tenant_id', $tenantId)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (DocumentCategory $category) => ['id' => $category->id, 'name' => $category->name, 'isArchived' => $category->archived_at !== null])
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVersion(Document $document, DocumentVersion $version): array
    {
        return [
            'id' => $version->id,
            'ordinal' => $version->ordinal,
            'originalFilename' => $version->original_filename,
            'mime' => $version->mime,
            'size' => $version->size,
            'authorName' => $version->author->name,
            'createdAt' => $version->created_at?->toIso8601String(),
            'isPublished' => $document->published_version_id === $version->id,
            'isLatest' => $document->latest_version_id === $version->id,
            'scanState' => $version->scan_state,
            'downloadUrl' => $version->isUsable() ? app(DocumentFileStorage::class)->signedDownloadUrl($document, $version) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatApprovalHistoryEntry(ApprovalRequest $approvalRequest): array
    {
        return [
            'id' => $approvalRequest->id,
            'state' => $approvalRequest->state,
            'dueAt' => $approvalRequest->due_at?->toIso8601String(),
            'steps' => $approvalRequest->steps->map(fn (ApprovalStep $step) => [
                'position' => $step->position,
                'reviewerName' => $step->reviewer->name,
                'state' => $step->state,
                'actedAt' => $step->acted_at?->toIso8601String(),
            ])->values(),
            'decisions' => $approvalRequest->decisions->map(fn ($decision) => $this->formatDecision($decision))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDecision(ApprovalDecision $decision): array
    {
        return [
            'reviewerName' => $decision->reviewer->name,
            'onBehalfOfName' => $decision->onBehalfOf?->name,
            'decision' => $decision->decision,
            'comment' => $decision->comment,
            'actedAt' => $decision->acted_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(Document $document): array
    {
        $today = Carbon::now('Asia/Tbilisi')->startOfDay();
        $expires = $document->expires_on;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'type' => $document->type,
            'status' => $document->status,
            'visibility' => $document->visibility ?? Document::VISIBILITY_WORKSPACE,
            'workspaceId' => $document->workspace_id,
            'workspaceTitle' => $document->workspace->title,
            'categoryName' => $document->category?->name,
            'ownerName' => $document->owner->name,
            'documentDate' => $document->document_date?->toDateString(),
            'expiresOn' => $expires?->toDateString(),
            'expiryState' => match (true) {
                $expires === null => null,
                $expires->lt($today) => 'expired',
                $expires->lte($today->copy()->addDays(self::EXPIRY_WINDOW_DAYS)) => 'soon',
                default => null,
            },
            'tags' => $document->tags ?? [],
            'isArchived' => $document->archived_at !== null,
            'createdAt' => $document->created_at?->toIso8601String(),
            'updatedAt' => $document->updated_at?->toIso8601String(),
        ];
    }
}
