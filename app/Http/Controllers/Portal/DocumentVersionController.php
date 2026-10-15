<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\UploadDocumentVersion;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreDocumentVersionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentVersionController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function store(
        StoreDocumentVersionRequest $request,
        CurrentTenant $currentTenant,
        Document $document,
        UploadDocumentVersion $action,
    ): RedirectResponse {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canEdit($document, $request->user()) && $document->archived_at === null, 403);

        $action->handle($document, $request->user(), $request->file('file'));

        return redirect()->route('documents.show', $document);
    }

    /**
     * Signed, short-lived download — cross-tenant or expired links 404/403
     * rather than 200-with-wrong-content. A pending or quarantined version
     * can never be downloaded regardless of who asks. VIEW-level readers get
     * only the published version; working versions need EDIT. Downloads
     * from confidential workspaces (contracts, HR) are audited.
     */
    public function download(Request $request, CurrentTenant $currentTenant, Document $document, DocumentVersion $version, AuditLogger $auditLogger): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($version->document_id === $document->id, 404);
        abort_unless($version->isUsable(), 403);

        $user = $request->user();

        if ($document->published_version_id === $version->id) {
            abort_unless($this->access->canView($document, $user), 403);
        } else {
            abort_unless($this->access->canEdit($document, $user), 403);
        }

        if ($document->workspace->isConfidential() || $document->isRestricted()) {
            $auditLogger->record($tenant->id, 'document.downloaded', $document, $user->id, [
                'version_id' => $version->id,
                'ordinal' => $version->ordinal,
            ]);
        }

        return Storage::disk('local')->download($version->storage_path, $version->original_filename);
    }
}
