<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Models\ApprovalDecision;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreDocumentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ფაილების ცენტრი" — the searchable document list/detail. Only tenant
 * staff (not guardian/student) get into the document center at all
 * (docs/07 §3 frames every role here as an employee role); visibility
 * within that is per-document (see visibleTo()).
 */
class DocumentController extends Controller
{
    /**
     * @var array<int, string>
     */
    public const STAFF_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::STAFF_ROLES), 403);

        $isDirectorOrAdmin = TenantMembership::userHasAnyActiveRole(
            $tenant->id, $user->id, [TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN],
        );

        $documents = Document::query()
            ->where('tenant_id', $tenant->id)
            ->with(['workspace', 'owner', 'latestVersion', 'publishedVersion'])
            ->when(! $isDirectorOrAdmin, fn ($query) => $query->where(function ($q) use ($user) {
                $q->where('status', Document::STATUS_APPROVED)
                    ->orWhere('owner_id', $user->id);
            }))
            ->when($request->string('search')->isNotEmpty(), fn ($query) => $query->where(
                'title', 'like', '%'.$request->string('search')->toString().'%',
            ))
            ->when($request->string('workspace_id')->isNotEmpty(), fn ($query) => $query->where(
                'workspace_id', (int) $request->string('workspace_id')->toString(),
            ))
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where(
                'status', $request->string('status')->toString(),
            ))
            ->latest('updated_at')
            ->get();

        return Inertia::render('portal/documents/index', [
            'workspaces' => DocumentWorkspace::query()->where('tenant_id', $tenant->id)->orderBy('title')->get(['id', 'title', 'classification']),
            'documents' => $documents->map(fn (Document $document) => $this->formatSummary($document))->values(),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'workspace_id' => $request->string('workspace_id')->toString(),
                'status' => $request->string('status')->toString(),
            ],
            'isDirectorOrAdmin' => $isDirectorOrAdmin,
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Document $document): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        $this->authorizeView($request, $tenant->id, $document);

        $document->load(['workspace', 'owner', 'latestVersion', 'publishedVersion', 'versions.author']);

        $approvalHistory = $document->versions->flatMap(function ($version) {
            return $version->approvalRequest !== null
                ? [$version->approvalRequest->load('decisions.reviewer')]
                : [];
        });

        return Inertia::render('portal/documents/show', [
            'document' => $this->formatSummary($document),
            'versions' => $document->versions->map(fn ($version) => $this->formatVersion($document, $version))->values(),
            'approvalHistory' => $approvalHistory->map(fn ($approvalRequest) => $this->formatApprovalHistoryEntry($approvalRequest))->values(),
            'canUpload' => $document->owner_id === $request->user()->id,
            'canSubmit' => $document->owner_id === $request->user()->id
                && in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_CHANGES_REQUESTED], true),
            'canDecide' => TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, [
                TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN,
            ]) && $document->status === Document::STATUS_IN_REVIEW && $document->latestVersion?->author_id !== $request->user()->id,
        ]);
    }

    public function store(StoreDocumentRequest $request, CurrentTenant $currentTenant, CreateDraftDocument $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::STAFF_ROLES), 403);

        $workspace = DocumentWorkspace::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $request->integer('workspace_id'))
            ->firstOrFail();

        $responsible = $request->integer('responsible_id')
            ? User::query()->find($request->integer('responsible_id'))
            : null;

        $document = $action->handle(
            tenantId: $tenant->id,
            workspace: $workspace,
            owner: $user,
            type: $request->string('type')->toString(),
            title: $request->string('title')->toString(),
            file: $request->file('file'),
            responsible: $responsible,
        );

        return redirect()->route('documents.show', $document)->with('documentId', $document->id);
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
            'downloadUrl' => app(DocumentFileStorage::class)->signedDownloadUrl($document, $version),
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
        return [
            'id' => $document->id,
            'title' => $document->title,
            'type' => $document->type,
            'status' => $document->status,
            'workspaceTitle' => $document->workspace->title,
            'ownerName' => $document->owner->name,
            'updatedAt' => $document->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeView(Request $request, int $tenantId, Document $document): void
    {
        if ($document->status === Document::STATUS_APPROVED) {
            abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::STAFF_ROLES), 403);

            return;
        }

        $isOwner = $document->owner_id === $request->user()->id;
        $isOversight = TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, [
            TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN,
        ]);

        abort_unless($isOwner || $isOversight, 403);
    }
}
