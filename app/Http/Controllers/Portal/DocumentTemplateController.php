<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ManageDocumentTemplates;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „შაბლონები“ — templates the user can see, and starting a new document
 * from one in a workspace they may add files to.
 */
class DocumentTemplateController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless($this->access->isStaff($tenant->id, $user), 403);

        $templates = $this->access->constrainVisible(Document::query(), $tenant->id, $user)
            ->where('documents.is_template', true)->whereNull('documents.archived_at')
            ->with(['workspace', 'category', 'publishedVersion'])->orderBy('documents.title')->get()
            ->filter(fn (Document $d) => $d->publishedVersion?->isUsable() ?? false);

        $workspaces = DocumentWorkspace::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->orderBy('title')->get()
            ->filter(fn (DocumentWorkspace $w) => $this->access->canCreateIn($w, $user));

        return Inertia::render('portal/documents/templates', [
            'templates' => $templates->map(fn (Document $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'workspace' => $d->workspace->title,
                'category' => $d->category?->name,
                'versionOrdinal' => $d->publishedVersion?->ordinal,
                'filename' => $d->publishedVersion?->original_filename,
            ])->values(),
            'workspaces' => $workspaces->map(fn (DocumentWorkspace $w) => ['id' => $w->id, 'title' => $w->title, 'requiresApproval' => $w->requiresApproval()])->values(),
        ]);
    }

    public function use(Request $request, CurrentTenant $currentTenant, Document $document, ManageDocumentTemplates $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
        ]);
        $workspace = DocumentWorkspace::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['workspace_id'])->firstOrFail();

        $created = $action->createFromTemplate($document, $workspace, $data['title'], $request->user());

        return redirect()->route('documents.show', $created)->with('toast', ['type' => 'success', 'message' => 'შაბლონიდან შეიქმნა ახალი დოკუმენტი.']);
    }

    public function toggle(Request $request, CurrentTenant $currentTenant, Document $document, ManageDocumentTemplates $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        $data = $request->validate(['is_template' => ['required', 'boolean']]);

        $action->setTemplate($document, (bool) $data['is_template'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $data['is_template'] ? 'დოკუმენტი შაბლონებს დაემატა.' : 'დოკუმენტი შაბლონებიდან ამოიღეს.']);
    }
}
