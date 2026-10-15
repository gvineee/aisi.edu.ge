<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ManageDocumentLibrary;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\DocumentAccessGrant;
use App\Domain\Documents\Models\DocumentAccessLevel;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "სივრცეები და წვდომა": workspaces (folders) with their default audience,
 * approval requirement and grants, plus document categories. Director/admin
 * create and configure; a workspace manager (by grant) sees and manages
 * the grants of the workspaces they manage only.
 */
class DocumentLibraryController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $isOversight = $this->access->isOversight($tenant->id, $user);
        $levels = $this->access->workspaceLevels($tenant->id, $user);
        $managed = array_keys(array_filter($levels, fn (int $level) => $level >= DocumentAccessLevel::MANAGE));

        abort_unless($isOversight || $managed !== [], 403);

        $workspaces = DocumentWorkspace::query()->where('tenant_id', $tenant->id)->whereIn('id', $managed)
            ->withCount('documents')->orderBy('title')->get();

        return Inertia::render('portal/documents/settings', [
            'canConfigure' => $isOversight,
            'workspaces' => $workspaces->map(fn (DocumentWorkspace $workspace) => [
                'id' => $workspace->id,
                'title' => $workspace->title,
                'description' => $workspace->description,
                'classification' => $workspace->classification,
                'visibility' => $workspace->visibility,
                'requiresApproval' => $workspace->requiresApproval(),
                'isConfidential' => (bool) $workspace->is_confidential,
                'isArchived' => $workspace->archived_at !== null,
                'documentsCount' => $workspace->documents_count,
                'grants' => DocumentAccessGrantController::grantsFor($tenant->id, DocumentAccessGrant::RESOURCE_WORKSPACE, $workspace->id),
            ])->values(),
            'categories' => $isOversight ? DocumentCategory::query()->where('tenant_id', $tenant->id)->withCount('documents')
                ->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (DocumentCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'sortOrder' => $category->sort_order,
                    'isArchived' => $category->archived_at !== null,
                    'documentsCount' => $category->documents_count,
                ])->values() : [],
            'classifications' => DocumentWorkspace::CLASSIFICATIONS,
            'staff' => DocumentAccessGrantController::staffOptions($tenant->id),
            'roleOptions' => DocumentAccessGrantController::roleOptions(),
        ]);
    }

    public function storeWorkspace(Request $request, CurrentTenant $currentTenant, ManageDocumentLibrary $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($this->access->isOversight($tenant->id, $request->user()), 403);

        $action->saveWorkspace($tenant->id, null, $this->workspaceData($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'სივრცე შეიქმნა.']);
    }

    public function updateWorkspace(Request $request, CurrentTenant $currentTenant, DocumentWorkspace $documentWorkspace, ManageDocumentLibrary $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($documentWorkspace->tenant_id === $tenant->id, 404);
        abort_unless($this->access->isOversight($tenant->id, $request->user()), 403);

        $action->saveWorkspace($tenant->id, $documentWorkspace, $this->workspaceData($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'სივრცე შენახულია.']);
    }

    public function archiveWorkspace(Request $request, CurrentTenant $currentTenant, DocumentWorkspace $documentWorkspace, ManageDocumentLibrary $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($documentWorkspace->tenant_id === $tenant->id, 404);
        abort_unless($this->access->isOversight($tenant->id, $request->user()), 403);
        $request->validate(['archived' => ['required', 'boolean']]);

        $action->setWorkspaceArchived($documentWorkspace, $request->boolean('archived'), $request->user());

        return back();
    }

    public function storeCategory(Request $request, CurrentTenant $currentTenant, ManageDocumentLibrary $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($this->access->isOversight($tenant->id, $request->user()), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999']]);

        $action->saveCategory($tenant->id, null, trim($data['name']), (int) ($data['sort_order'] ?? 0), null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'კატეგორია დაემატა.']);
    }

    public function updateCategory(Request $request, CurrentTenant $currentTenant, DocumentCategory $documentCategory, ManageDocumentLibrary $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($documentCategory->tenant_id === $tenant->id, 404);
        abort_unless($this->access->isOversight($tenant->id, $request->user()), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $action->saveCategory($tenant->id, $documentCategory, trim($data['name']), (int) ($data['sort_order'] ?? 0),
            $request->has('archived') ? $request->boolean('archived') : null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'კატეგორია შენახულია.']);
    }

    /**
     * @return array{title: string, description: string|null, classification: string, visibility: string, requires_approval: bool, is_confidential: bool}
     */
    private function workspaceData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'classification' => ['required', Rule::in(DocumentWorkspace::CLASSIFICATIONS)],
            'visibility' => ['required', Rule::in([DocumentWorkspace::VISIBILITY_ALL_STAFF, DocumentWorkspace::VISIBILITY_RESTRICTED])],
            'requires_approval' => ['required', 'boolean'],
            'is_confidential' => ['required', 'boolean'],
        ]);

        return [
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'classification' => $data['classification'],
            'visibility' => $data['visibility'],
            'requires_approval' => $request->boolean('requires_approval'),
            'is_confidential' => $request->boolean('is_confidential'),
        ];
    }
}
