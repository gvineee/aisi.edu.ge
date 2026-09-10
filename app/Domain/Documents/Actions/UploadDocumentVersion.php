<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentFileStorage;
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
    ) {}

    public function handle(Document $document, User $author, UploadedFile $file): DocumentVersion
    {
        $this->storage->assertAllowed($file);

        return DB::transaction(function () use ($document, $author, $file) {
            $document = Document::query()->where('id', $document->id)->lockForUpdate()->firstOrFail();

            if ($document->latest_version_id !== null) {
                ApprovalRequest::query()
                    ->where('document_version_id', $document->latest_version_id)
                    ->where('state', ApprovalRequest::STATE_PENDING)
                    ->update(['state' => ApprovalRequest::STATE_SUPERSEDED]);
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
                'scan_state' => DocumentVersion::SCAN_CLEAN,
            ]);
            $version->tenant_id = $document->tenant_id;
            $version->save();

            $document->latest_version_id = $version->id;
            $document->status = Document::STATUS_DRAFT;
            $document->save();

            $this->auditLogger->record($document->tenant_id, 'document.version_uploaded', $document, $author->id, [
                'version_id' => $version->id,
                'ordinal' => $nextOrdinal,
            ]);

            return $version;
        });
    }
}
