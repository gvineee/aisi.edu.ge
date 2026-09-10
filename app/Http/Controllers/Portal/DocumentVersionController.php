<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\UploadDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreDocumentVersionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentVersionController extends Controller
{
    public function store(
        StoreDocumentVersionRequest $request,
        CurrentTenant $currentTenant,
        Document $document,
        UploadDocumentVersion $action,
    ): RedirectResponse {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($document->owner_id === $request->user()->id, 403);

        $action->handle($document, $request->user(), $request->file('file'));

        return redirect()->route('documents.show', $document);
    }

    /**
     * Signed, short-lived download — cross-tenant or expired links 404/403
     * rather than 200-with-wrong-content. A quarantined version can never
     * be downloaded regardless of who asks.
     */
    public function download(Request $request, CurrentTenant $currentTenant, Document $document, DocumentVersion $version): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($version->document_id === $document->id, 404);
        abort_unless($version->isUsable(), 403);

        $isPublished = $document->published_version_id === $version->id;

        if (! $isPublished) {
            $isOwner = $document->owner_id === $request->user()->id;
            $isOversight = TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, [
                TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN,
            ]);
            abort_unless($isOwner || $isOversight, 403);
        } else {
            abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, DocumentController::STAFF_ROLES), 403);
        }

        return Storage::disk('local')->download($version->storage_path, $version->original_filename);
    }
}
