<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Jobs\ScanDocumentVersion;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Adds a new working version to an existing document. Never touches the
 * `published_version_id` — an already-approved published version stays
 * exactly as readers currently see it (spec §4: "დამტკიცებული v1-ის
 * შემდეგ v2 მონახაზის არსებობა მკითხველს v2-ს არ აჩვენებს").
 *
 * In a workspace that does not require approval there is no review step:
 * the new upload simply becomes the current (published) version.
 *
 * If a pending approval request exists for the current latest version
 * (i.e. someone is mid-review), uploading a new version supersedes that
 * request rather than leaving it dangling against a version that's about
 * to be superseded by a newer draft.
 */
class UploadDocumentVersion
{
    public function __construct(
        private readonly DocumentFileStorage $storage,
        private readonly AuditLogger $auditLogger,
        private readonly ManageDocumentAcknowledgements $acknowledgements,
    ) {}

    public function handle(Document $document, User $author, UploadedFile $file): DocumentVersion
    {
        $this->storage->assertAllowed($file);

        return DB::transaction(function () use ($document, $author, $file) {
            $document = Document::query()->where('id', $document->id)->lockForUpdate()->firstOrFail();
            $requiresApproval = $document->workspace->requiresApproval();

            if ($document->latest_version_id !== null) {
                ApprovalRequest::supersedePendingFor($document->tenant_id, $document->latest_version_id);
            }

            $nextOrdinal = (int) DocumentVersion::query()
                ->where('document_id', $document->id)
                ->max('ordinal') + 1;

            $stored = $this->storage->store($file, $document->tenant_id, $document->id, $nextOrdinal);

            $version = new DocumentVersion([
                'document_id' => $document->id,
                'ordinal' => $nextOrdinal,
                'storage_path' => $stored['storage_path'],
                'checksum' => $stored['checksum'],
                'original_filename' => $stored['original_filename'],
                'mime' => $stored['mime'],
                'size' => $stored['size'],
                'author_id' => $author->id,
                'scan_state' => DocumentVersion::SCAN_PENDING,
            ]);
            $version->tenant_id = $document->tenant_id;
            $version->save();

            $document->latest_version_id = $version->id;
            $republished = false;

            if ($requiresApproval) {
                $document->status = Document::STATUS_DRAFT;
            } else {
                $document->status = Document::STATUS_ACTIVE;
                $document->published_version_id = $version->id;
                $republished = true;
            }

            $document->save();

            if ($republished) {
                $this->acknowledgements->onPublished($document, $version, $author->id);
            }

            ScanDocumentVersion::dispatch($document->tenant_id, $version->id);

            $this->auditLogger->record($document->tenant_id, 'document.version_uploaded', $document, $author->id, [
                'version_id' => $version->id,
                'ordinal' => $nextOrdinal,
            ]);

            return $version;
        });
    }
}
